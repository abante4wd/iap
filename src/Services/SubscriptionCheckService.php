<?php

namespace Fukazawa\Iap\Services;

use Fukazawa\Iap\Contracts\SubscriptionRepositoryInterface;
use Fukazawa\Iap\Enums\SubscriptionStatus;
use Fukazawa\Iap\Store\StoreVerifierFactory;

class SubscriptionCheckService
{
    public function __construct(
        private StoreVerifierFactory $verifierFactory,
        private SubscriptionRepositoryInterface $subscriptionRepo,
    ) {}

    /**
     * @param  callable|null  $onProgress  fn(string $message): void
     * @return array{checked: int, updated: int, errors: int}
     */
    public function checkExpiring(?callable $onProgress = null): array
    {
        $before = new \DateTimeImmutable('+1 day');
        $statuses = [SubscriptionStatus::Active, SubscriptionStatus::GracePeriod];

        $subscriptions = $this->subscriptionRepo->findExpiringSubscriptions($before, $statuses);

        $onProgress && $onProgress('Checking '.count($subscriptions).' subscriptions...');

        $updated = 0;
        $errors = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $verifier = $this->verifierFactory->make($subscription->platform);

                $result = $verifier->verifySubscription(
                    $subscription->storeProductId,
                    $subscription->currentTransactionId,
                );

                if ($result->subscriptionInfo) {
                    $this->subscriptionRepo->updateFromStoreInfo(
                        $subscription->id,
                        $result->subscriptionInfo,
                    );
                    $updated++;
                }
            } catch (\Exception $e) {
                $errors++;
                $onProgress && $onProgress("Failed to check subscription {$subscription->id}: {$e->getMessage()}");
            }
        }

        $onProgress && $onProgress("Updated {$updated} subscriptions.");

        return [
            'checked' => count($subscriptions),
            'updated' => $updated,
            'errors' => $errors,
        ];
    }
}
