<?php

declare(strict_types=1);

namespace App\Services\Iap;

use App\Models\Subscription;
use DateTimeImmutable;
use Fukazawa\Iap\Contracts\SubscriptionRepositoryInterface;
use Fukazawa\Iap\DTO\SubscriptionData;
use Fukazawa\Iap\DTO\SubscriptionInfo;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\SubscriptionStatus;

class EloquentSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function upsert(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        SubscriptionInfo $info,
    ): void {
        Subscription::updateOrCreate(
            [
                'user_id' => $userId,
                'platform' => $platform->value,
                'original_transaction_id' => $info->originalTransactionId,
            ],
            [
                'product_id' => $productId,
                'current_transaction_id' => $info->currentTransactionId,
                'status' => $info->status,
                'starts_at' => $info->startsAt,
                'expires_at' => $info->expiresAt,
                'auto_renewing' => $info->autoRenewing,
            ],
        );
    }

    /**
     * @param  SubscriptionStatus[]  $statuses
     * @return SubscriptionData[]
     */
    public function findExpiringSubscriptions(DateTimeImmutable $before, array $statuses): array
    {
        $statusValues = array_map(fn (SubscriptionStatus $s) => $s->value, $statuses);

        return Subscription::whereIn('status', $statusValues)
            ->where('expires_at', '<=', $before->format('Y-m-d H:i:s'))
            ->get()
            ->map(fn (Subscription $sub) => $this->toDto($sub))
            ->all();
    }

    public function updateFromStoreInfo(int|string $subscriptionId, SubscriptionInfo $info): void
    {
        Subscription::where('id', $subscriptionId)->update([
            'current_transaction_id' => $info->currentTransactionId,
            'status' => $info->status,
            'starts_at' => $info->startsAt,
            'expires_at' => $info->expiresAt,
            'auto_renewing' => $info->autoRenewing,
        ]);
    }

    private function toDto(Subscription $sub): SubscriptionData
    {
        return new SubscriptionData(
            id: $sub->id,
            userId: $sub->user_id,
            productId: $sub->product_id,
            platform: Platform::from($sub->platform),
            originalTransactionId: $sub->original_transaction_id,
            currentTransactionId: $sub->current_transaction_id,
            storeProductId: $sub->product?->google_product_id ?? $sub->product?->product_id ?? '',
            status: SubscriptionStatus::from($sub->status),
            expiresAt: DateTimeImmutable::createFromMutable($sub->expires_at),
        );
    }
}
