<?php

namespace Abante4wd\Iap\Notifications;

use Abante4wd\Iap\Contracts\ServerNotificationHandlerInterface;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Services\DeferredPurchaseService;

/**
 * Google Play Real-time Developer Notifications (RTDN) を処理するハンドラー。
 *
 * Pub/Sub 経由または直接 POST されたペイロードを解析し、
 * oneTimeProductNotification・subscriptionNotification・voidedPurchaseNotification
 * の各通知タイプに応じた処理を行う。
 */
class GooglePlayNotificationHandler implements ServerNotificationHandlerInterface
{
    /**
     * @param DeferredPurchaseService $deferredService 保留中購入の完了・キャンセルサービス
     */
    public function __construct(
        private DeferredPurchaseService $deferredService,
    ) {}

    /**
     * Google Play Real-time Developer Notifications (RTDN) を処理する。
     *
     * Pub/Sub ラップメッセージ（message.data が base64 エンコードされている形式）にも対応する。
     *
     * @param string $payload Google Play から受信した生のリクエストボディ（JSON 文字列）
     * @return array{type: string, action: string, details: array} 処理結果
     */
    public function handle(string $payload): array
    {
        $data = json_decode($payload, true);
        if (! $data) {
            return ['type' => 'error', 'action' => 'none', 'details' => ['error' => 'Invalid JSON payload']];
        }

        // Pub/Sub メッセージのデコード
        $messageData = $data['message']['data'] ?? null;
        if ($messageData) {
            $decoded = json_decode(base64_decode($messageData), true);
            if ($decoded) {
                $data = $decoded;
            }
        }

        // oneTimeProductNotification の処理
        $oneTimeNotification = $data['oneTimeProductNotification'] ?? null;
        if ($oneTimeNotification) {
            return $this->handleOneTimeProductNotification($oneTimeNotification);
        }

        // subscriptionNotification の処理
        $subscriptionNotification = $data['subscriptionNotification'] ?? null;
        if ($subscriptionNotification) {
            return $this->handleSubscriptionNotification($subscriptionNotification);
        }

        // voidedPurchaseNotification の処理
        $voidedNotification = $data['voidedPurchaseNotification'] ?? null;
        if ($voidedNotification) {
            return $this->handleVoidedPurchaseNotification($voidedNotification);
        }

        return ['type' => 'unknown', 'action' => 'ignored', 'details' => $data];
    }

    /**
     * 単品購入通知（oneTimeProductNotification）を処理する。
     *
     * notificationType 1（購入完了）: 保留購入を完了させる。
     * notificationType 2（キャンセル）: 保留購入をキャンセルする。
     *
     * @param array $notification oneTimeProductNotification オブジェクト
     * @return array{type: string, action: string, details: array}
     */
    private function handleOneTimeProductNotification(array $notification): array
    {
        $notificationType = $notification['notificationType'] ?? 0;
        $purchaseToken = $notification['purchaseToken'] ?? '';
        $sku = $notification['sku'] ?? '';

        // notificationType: 1=purchased, 2=canceled
        // 保留→購入完了
        if ($notificationType === 1) {
            $purchase = $this->deferredService->completePending(Platform::Google, $purchaseToken);

            return [
                'type' => 'ONE_TIME_PRODUCT_PURCHASED',
                'action' => $purchase ? 'completed' : 'not_found',
                'details' => [
                    'purchaseToken' => $purchaseToken,
                    'sku' => $sku,
                ],
            ];
        }

        // キャンセル
        if ($notificationType === 2) {
            $this->deferredService->cancelPending(Platform::Google, $purchaseToken, 'cancelled_by_store');

            return [
                'type' => 'ONE_TIME_PRODUCT_CANCELED',
                'action' => 'cancelled',
                'details' => [
                    'purchaseToken' => $purchaseToken,
                    'sku' => $sku,
                ],
            ];
        }

        return [
            'type' => 'ONE_TIME_PRODUCT_UNKNOWN',
            'action' => 'ignored',
            'details' => [
                'notificationType' => $notificationType,
                'purchaseToken' => $purchaseToken,
                'sku' => $sku,
            ],
        ];
    }

    /**
     * サブスクリプション通知（subscriptionNotification）を処理する。
     *
     * 更新・解約・アップグレード等の詳細は呼び出し元で notificationType を参照して判断する。
     *
     * @param array $notification subscriptionNotification オブジェクト
     * @return array{type: string, action: string, details: array}
     */
    private function handleSubscriptionNotification(array $notification): array
    {
        $notificationType = $notification['notificationType'] ?? 0;
        $purchaseToken = $notification['purchaseToken'] ?? '';
        $subscriptionId = $notification['subscriptionId'] ?? '';

        return [
            'type' => 'SUBSCRIPTION_NOTIFICATION',
            'action' => 'subscription_updated',
            'details' => [
                'notificationType' => $notificationType,
                'purchaseToken' => $purchaseToken,
                'subscriptionId' => $subscriptionId,
            ],
        ];
    }

    /**
     * 購入無効化通知（voidedPurchaseNotification）を処理する。
     *
     * 返金・チャージバック等で購入が無効化された場合に対応する保留レコードをキャンセルする。
     *
     * @param array $notification voidedPurchaseNotification オブジェクト
     * @return array{type: string, action: string, details: array}
     */
    private function handleVoidedPurchaseNotification(array $notification): array
    {
        $purchaseToken = $notification['purchaseToken'] ?? '';
        $orderId = $notification['orderId'] ?? '';

        $this->deferredService->cancelPending(Platform::Google, $purchaseToken, 'voided');

        return [
            'type' => 'VOIDED_PURCHASE',
            'action' => 'cancelled',
            'details' => [
                'purchaseToken' => $purchaseToken,
                'orderId' => $orderId,
            ],
        ];
    }
}
