<?php

namespace Fukazawa\Iap\Contracts;

use Fukazawa\Iap\DTO\SubscriptionData;
use Fukazawa\Iap\DTO\SubscriptionInfo;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\SubscriptionStatus;

interface SubscriptionRepositoryInterface
{
    public function upsert(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        SubscriptionInfo $info,
    ): void;

    /**
     * @param  SubscriptionStatus[]  $statuses
     * @return SubscriptionData[]
     */
    public function findExpiringSubscriptions(\DateTimeImmutable $before, array $statuses): array;

    public function updateFromStoreInfo(int|string $subscriptionId, SubscriptionInfo $info): void;
}
