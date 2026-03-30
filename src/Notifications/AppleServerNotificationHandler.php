<?php

namespace Fukazawa\Iap\Notifications;

use Fukazawa\Iap\Contracts\ServerNotificationHandlerInterface;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Services\DeferredPurchaseService;

class AppleServerNotificationHandler implements ServerNotificationHandlerInterface
{
    public function __construct(
        private DeferredPurchaseService $deferredService,
    ) {}

    /**
     * Apple App Store Server Notifications V2 を処理する
     *
     * @return array{type: string, action: string, details: array}
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

    private function extractTransactionInfo(array $notification): array
    {
        $signedTransactionInfo = $notification['data']['signedTransactionInfo'] ?? null;
        if (! $signedTransactionInfo) {
            return [];
        }

        return $this->decodeJws($signedTransactionInfo);
    }

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
