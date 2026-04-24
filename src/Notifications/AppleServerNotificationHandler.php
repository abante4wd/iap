<?php

namespace Abante4wd\Iap\Notifications;

use Abante4wd\Iap\Contracts\ServerNotificationHandlerInterface;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Services\DeferredPurchaseService;

/**
 * Apple App Store Server Notifications V2 を処理するハンドラー。
 *
 * signedPayload（JWS 形式）をデコードして notificationType に応じた処理を行う。
 * Ask to Buy の承認・拒否（ONE_TIME_CHARGE）やサブスクリプション更新イベントを扱う。
 */
class AppleServerNotificationHandler implements ServerNotificationHandlerInterface
{
    /**
     * @param DeferredPurchaseService $deferredService 保留中購入の完了・キャンセルサービス
     */
    public function __construct(
        private DeferredPurchaseService $deferredService,
    ) {}

    /**
     * Apple App Store Server Notifications V2 を処理する。
     *
     * @param string $payload Apple から受信した生のリクエストボディ（JSON 文字列）
     * @return array{type: string, action: string, details: array} 処理結果
     */
    public function handle(string $payload): array
    {
        $data = json_decode($payload, true);
        if (! $data) {
            return ['type' => 'error', 'action' => 'none', 'details' => ['error' => 'Invalid JSON payload']];
        }

        $signedPayload = $data['signedPayload'] ?? null;
        if (! $signedPayload) {
            return ['type' => 'error', 'action' => 'none', 'details' => ['error' => 'No signedPayload found']];
        }

        $notification = $this->decodeJws($signedPayload);
        $notificationType = $notification['notificationType'] ?? '';
        $subtype = $notification['subtype'] ?? '';

        $transactionInfo = $this->extractTransactionInfo($notification);
        $transactionId = (string) ($transactionInfo['transactionId'] ?? '');

        return match ($notificationType) {
            'DID_CHANGE_RENEWAL_STATUS',
            'SUBSCRIBED',
            'DID_RENEW' => $this->handleSubscriptionEvent($notificationType, $subtype, $transactionInfo),

            'REVOKE' => $this->handleRevoke($transactionInfo),

            // Ask to Buy の承認完了
            'CONSUMPTION_REQUEST' => $this->handleConsumptionRequest($transactionInfo),

            // 保留中の購入が承認された
            'ONE_TIME_CHARGE' => $this->handleOneTimeCharge($subtype, $transactionInfo),

            default => [
                'type' => $notificationType,
                'action' => 'ignored',
                'details' => ['subtype' => $subtype, 'transactionId' => $transactionId],
            ],
        };
    }

    /**
     * サブスクリプション系イベント（更新・新規契約・自動更新）を処理する。
     *
     * @param string $type            notificationType
     * @param string $subtype         通知のサブタイプ
     * @param array  $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleSubscriptionEvent(string $type, string $subtype, array $transactionInfo): array
    {
        return [
            'type' => $type,
            'action' => 'subscription_updated',
            'details' => [
                'subtype' => $subtype,
                'transactionId' => $transactionInfo['transactionId'] ?? '',
                'productId' => $transactionInfo['productId'] ?? '',
            ],
        ];
    }

    /**
     * 購入取り消し（REVOKE）通知を処理する。
     *
     * 保留中レコードをキャンセルし、取り消し理由を details に含める。
     *
     * @param array $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleRevoke(array $transactionInfo): array
    {
        $purchaseToken = (string) ($transactionInfo['transactionId'] ?? '');
        $this->deferredService->cancelPending(Platform::Apple, $purchaseToken, 'revoked');

        return [
            'type' => 'REVOKE',
            'action' => 'cancelled',
            'details' => [
                'transactionId' => $transactionInfo['transactionId'] ?? '',
                'revocationReason' => $transactionInfo['revocationReason'] ?? null,
            ],
        ];
    }

    /**
     * 消費リクエスト（CONSUMPTION_REQUEST）通知を処理する。
     *
     * @param array $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleConsumptionRequest(array $transactionInfo): array
    {
        return [
            'type' => 'CONSUMPTION_REQUEST',
            'action' => 'consumption_requested',
            'details' => [
                'transactionId' => $transactionInfo['transactionId'] ?? '',
                'productId' => $transactionInfo['productId'] ?? '',
            ],
        ];
    }

    /**
     * 単品購入イベント（ONE_TIME_CHARGE）を処理する。
     *
     * ACCEPTED: 保留購入を完了させる。DECLINED: 保留購入をキャンセルする。
     *
     * @param string $subtype         'ACCEPTED' または 'DECLINED'
     * @param array  $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleOneTimeCharge(string $subtype, array $transactionInfo): array
    {
        $purchaseToken = (string) ($transactionInfo['transactionId'] ?? '');

        if ($subtype === 'ACCEPTED') {
            $purchase = $this->deferredService->completePending(Platform::Apple, $purchaseToken);

            return [
                'type' => 'ONE_TIME_CHARGE',
                'action' => $purchase ? 'completed' : 'not_found',
                'details' => [
                    'subtype' => $subtype,
                    'transactionId' => $purchaseToken,
                ],
            ];
        }

        if ($subtype === 'DECLINED') {
            $this->deferredService->cancelPending(Platform::Apple, $purchaseToken, 'declined_by_parent');

            return [
                'type' => 'ONE_TIME_CHARGE',
                'action' => 'cancelled',
                'details' => [
                    'subtype' => $subtype,
                    'transactionId' => $purchaseToken,
                ],
            ];
        }

        return [
            'type' => 'ONE_TIME_CHARGE',
            'action' => 'ignored',
            'details' => ['subtype' => $subtype, 'transactionId' => $purchaseToken],
        ];
    }

    /**
     * 通知ペイロードからトランザクション情報を抽出する。
     *
     * data.signedTransactionInfo が存在する場合のみデコードして返す。
     *
     * @param array $notification デコード済みの通知ペイロード
     * @return array デコード済みのトランザクション情報。signedTransactionInfo がない場合は空配列
     */
    private function extractTransactionInfo(array $notification): array
    {
        $signedTransactionInfo = $notification['data']['signedTransactionInfo'] ?? null;
        if (! $signedTransactionInfo) {
            return [];
        }

        return $this->decodeJws($signedTransactionInfo);
    }

    /**
     * JWS の第2セグメント（ペイロード）を署名検証なしでデコードして返す。
     *
     * サーバー通知の signedPayload / signedTransactionInfo は Apple のルート CA で
     * 署名されているが、通知ハンドラーでは内容の参照のみを目的としている。
     *
     * @param string $jws JWS 文字列（"header.payload.signature" 形式）
     * @return array デコード済みのペイロード配列。フォーマット不正の場合は空配列
     */
    private function decodeJws(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            return [];
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);

        return $payload ?: [];
    }
}
