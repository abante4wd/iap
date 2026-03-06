<?php

declare(strict_types=1);

namespace App;

use DateTimeImmutable;
use Fukazawa\Iap\Contracts\SubscriptionRepositoryInterface;
use Fukazawa\Iap\DTO\SubscriptionInfo;
use Fukazawa\Iap\Enums\Platform;

class InMemorySubscriptionRepository implements SubscriptionRepositoryInterface
{
    /** @var array<int, array<string, mixed>> */
    private array $subscriptions = [];

    private int $nextId = 1;

    public function upsert(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        SubscriptionInfo $info,
    ): void {
        $this->subscriptions[$this->nextId++] = [
            'userId' => $userId,
            'productId' => $productId,
            'platform' => $platform,
            'info' => $info,
        ];

        echo "  [SubscriptionRepo] Upserted subscription for user #{$userId}, product #{$productId}\n";
    }

    public function findExpiringSubscriptions(DateTimeImmutable $before, array $statuses): array
    {
        // サンプルでは空を返す
        return [];
    }

    public function updateFromStoreInfo(int|string $subscriptionId, SubscriptionInfo $info): void
    {
        echo "  [SubscriptionRepo] Updated subscription #{$subscriptionId} from store info\n";
    }
}
