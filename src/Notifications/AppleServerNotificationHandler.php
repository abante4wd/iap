<?php

namespace Abante4wd\Iap\Notifications;

use Abante4wd\Iap\Contracts\ServerNotificationHandlerInterface;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Services\DeferredPurchaseService;
use Abante4wd\Iap\Store\AppleJwsVerifier;

class AppleServerNotificationHandler implements ServerNotificationHandlerInterface
{
    public function __construct(
        private DeferredPurchaseService $deferredService,
        private AppleJwsVerifier $jwsVerifier = new AppleJwsVerifier(),
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

        try {
            $notification = $this->decodeJws($signedPayload);
        } catch (\Exception $e) {
            return ['type' => 'error', 'action' => 'none', 'details' => ['error' => 'JWS verification failed: ' . $e->getMessage()]];
        }

        $notificationType = $notification['notificationType'] ?? '';
        $subtype = $notification['subtype'] ?? '';

        $transactionInfo = $this->extractTransactionInfo($notification);
        $transactionId = (string) ($transactionInfo['transactionId'] ?? '');

        return match ($notificationType) {
            'DID_CHANGE_RENEWAL_STATUS',
            'SUBSCRIBED',
            'DID_RENEW' => $this->handleSubscriptionEvent($notificationType, $subtype, $transactionInfo),

            'REVOKE' => $this->handleRevoke($transactionInfo),

            // 消耗品返金申請時に Apple がサーバーに消費状況を問い合わせる通知
            'CONSUMPTION_REQUEST' => $this->handleConsumptionRequest($transactionInfo),

            // 保留中の購入が承認/却下された
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

    private function extractTransactionInfo(array $notification): array
    {
        $signedTransactionInfo = $notification['data']['signedTransactionInfo'] ?? null;
        if (! $signedTransactionInfo) {
            return [];
        }

        try {
            return $this->decodeJws($signedTransactionInfo);
        } catch (\Exception) {
            return [];
        }
    }

    protected function decodeJws(string $jws): array
    {
        return $this->jwsVerifier->verify($jws);
    }
}
