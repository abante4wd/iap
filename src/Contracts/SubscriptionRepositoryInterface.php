<?php

namespace Abante4wd\Iap\Contracts;

use Abante4wd\Iap\DTO\SubscriptionData;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\SubscriptionStatus;

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
