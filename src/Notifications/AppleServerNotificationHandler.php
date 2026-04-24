<?php

namespace Abante4wd\Iap\Notifications;

use Abante4wd\Iap\Contracts\ServerNotificationHandlerInterface;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Services\DeferredPurchaseService;
use Abante4wd\Iap\Store\AppleJwsVerifier;

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
     * @param AppleJwsVerifier        $jwsVerifier      JWS 署名検証器
     */
    public function __construct(
        private DeferredPurchaseService $deferredService,
        private AppleJwsVerifier $jwsVerifier = new AppleJwsVerifier(),
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

        try {
            $notification = $this->decodeJws($signedPayload);
        } catch (\RuntimeException $e) {
            return ['type' => 'error', 'action' => 'none', 'details' => ['error' => 'JWS verification failed: ' . $e->getMessage()]];
        }

        $notificationType = $notification['notificationType'] ?? '';
        $subtype = $notification['subtype'] ?? '';

        try {
            $transactionInfo = $this->extractTransactionInfo($notification);
        } catch (\RuntimeException $e) {
            return ['type' => 'error', 'action' => 'none', 'details' => ['error' => 'Transaction JWS verification failed: ' . $e->getMessage()]];
        }
        $transactionId = (string) ($transactionInfo['transactionId'] ?? '');

        return match ($notificationType) {
            'DID_CHANGE_RENEWAL_STATUS',
            'DID_CHANGE_RENEWAL_PREF',
            'OFFER_REDEEMED',
            'PRICE_INCREASE',
            'SUBSCRIBED',
            'DID_RENEW' => $this->handleSubscriptionEvent($notificationType, $subtype, $transactionInfo),

            'EXPIRED',
            'GRACE_PERIOD_EXPIRED' => $this->handleSubscriptionExpired($notificationType, $subtype, $transactionInfo),

            'DID_FAIL_TO_RENEW' => $this->handleBillingFailed($subtype, $transactionInfo),

            'REVOKE' => $this->handleRevoke($transactionInfo),

            'REFUND' => $this->handleRefund($transactionInfo),
            'REFUND_DECLINED' => $this->handleRefundDeclined($transactionInfo),
            'REFUND_REVERSED' => $this->handleRefundReversed($transactionInfo),

            // 消耗品の返金申請時に Apple がサーバーに消費状況を問い合わせる通知
            'CONSUMPTION_REQUEST' => $this->handleConsumptionRequest($transactionInfo),

            // 保留中の購入が承認/却下された
            'ONE_TIME_CHARGE' => $this->handleOneTimeCharge($subtype, $transactionInfo),

            'TEST' => ['type' => 'TEST', 'action' => 'test', 'details' => []],

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
     * サブスクリプション期限切れ（EXPIRED / GRACE_PERIOD_EXPIRED）通知を処理する。
     *
     * @param string $type            notificationType
     * @param string $subtype         通知のサブタイプ
     * @param array  $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleSubscriptionExpired(string $type, string $subtype, array $transactionInfo): array
    {
        return [
            'type' => $type,
            'action' => 'subscription_expired',
            'details' => [
                'subtype' => $subtype,
                'transactionId' => $transactionInfo['transactionId'] ?? '',
                'productId' => $transactionInfo['productId'] ?? '',
            ],
        ];
    }

    /**
     * 課金失敗（DID_FAIL_TO_RENEW）通知を処理する。
     *
     * @param string $subtype         通知のサブタイプ
     * @param array  $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleBillingFailed(string $subtype, array $transactionInfo): array
    {
        return [
            'type' => 'DID_FAIL_TO_RENEW',
            'action' => 'subscription_billing_failed',
            'details' => [
                'subtype' => $subtype,
                'transactionId' => $transactionInfo['transactionId'] ?? '',
                'productId' => $transactionInfo['productId'] ?? '',
            ],
        ];
    }

    /**
     * 返金（REFUND）通知を処理する。
     *
     * @param array $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleRefund(array $transactionInfo): array
    {
        return [
            'type' => 'REFUND',
            'action' => 'refunded',
            'details' => [
                'transactionId' => $transactionInfo['transactionId'] ?? '',
                'productId' => $transactionInfo['productId'] ?? '',
                'revocationReason' => $transactionInfo['revocationReason'] ?? null,
            ],
        ];
    }

    /**
     * 返金拒否（REFUND_DECLINED）通知を処理する。
     *
     * @param array $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleRefundDeclined(array $transactionInfo): array
    {
        return [
            'type' => 'REFUND_DECLINED',
            'action' => 'refund_declined',
            'details' => [
                'transactionId' => $transactionInfo['transactionId'] ?? '',
                'productId' => $transactionInfo['productId'] ?? '',
            ],
        ];
    }

    /**
     * 返金取り消し（REFUND_REVERSED）通知を処理する。
     *
     * @param array $transactionInfo デコード済みのトランザクション情報
     * @return array{type: string, action: string, details: array}
     */
    private function handleRefundReversed(array $transactionInfo): array
    {
        return [
            'type' => 'REFUND_REVERSED',
            'action' => 'refund_reversed',
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
                'details' => ['subtype' => $subtype, 'transactionId' => $purchaseToken],
            ];
        }

        if ($subtype === 'DECLINED') {
            $this->deferredService->cancelPending(Platform::Apple, $purchaseToken, 'declined_by_parent');

            return [
                'type' => 'ONE_TIME_CHARGE',
                'action' => 'cancelled',
                'details' => ['subtype' => $subtype, 'transactionId' => $purchaseToken],
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
     * JWS 署名検証に失敗した場合は例外を呼び出し元に伝播させる。
     *
     * @param array $notification デコード済みの通知ペイロード
     * @return array デコード済みのトランザクション情報。signedTransactionInfo がない場合は空配列
     * @throws \RuntimeException 署名検証に失敗した場合
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
     * JWS を署名検証してペイロードをデコードして返す。
     *
     * @param string $jws JWS 文字列（"header.payload.signature" 形式）
     * @return array デコード済みのペイロード配列
     * @throws \RuntimeException 署名検証に失敗した場合
     */
    protected function decodeJws(string $jws): array
    {
        return $this->jwsVerifier->verify($jws);
    }
}
