<?php

declare(strict_types=1);

namespace App\Services\Iap;

use DateTimeImmutable;
use Fukazawa\Iap\Contracts\SubscriptionRepositoryInterface;
use Fukazawa\Iap\DTO\SubscriptionData;
use Fukazawa\Iap\DTO\SubscriptionInfo;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\SubscriptionStatus;
use Illuminate\Support\Facades\DB;

class QueryBuilderSubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function upsert(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        SubscriptionInfo $info,
    ): void {
        $existing = DB::table('subscriptions')
            ->where('user_id', $userId)
            ->where('platform', $platform->value)
            ->where('original_transaction_id', $info->originalTransactionId)
            ->first();

        $data = [
            'product_id' => $productId,
            'current_transaction_id' => $info->currentTransactionId,
            'status' => $info->status,
            'starts_at' => $info->startsAt->format('Y-m-d H:i:s'),
            'expires_at' => $info->expiresAt->format('Y-m-d H:i:s'),
            'auto_renewing' => $info->autoRenewing,
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('subscriptions')->where('id', $existing->id)->update($data);
        } else {
            DB::table('subscriptions')->insert(array_merge($data, [
                'user_id' => $userId,
                'platform' => $platform->value,
                'original_transaction_id' => $info->originalTransactionId,
                'created_at' => now(),
            ]));
        }
    }

    /**
     * @param  SubscriptionStatus[]  $statuses
     * @return SubscriptionData[]
     */
    public function findExpiringSubscriptions(DateTimeImmutable $before, array $statuses): array
    {
        $statusValues = array_map(fn (SubscriptionStatus $s) => $s->value, $statuses);

        $rows = DB::table('subscriptions')
            ->leftJoin('products', 'subscriptions.product_id', '=', 'products.id')
            ->whereIn('subscriptions.status', $statusValues)
            ->where('subscriptions.expires_at', '<=', $before->format('Y-m-d H:i:s'))
            ->select('subscriptions.*', 'products.google_product_id', 'products.product_id as product_product_id')
            ->get();

        return array_map(
            fn (object $row) => $this->toDto($row),
            $rows->all(),
        );
    }

    public function updateFromStoreInfo(int|string $subscriptionId, SubscriptionInfo $info): void
    {
        DB::table('subscriptions')->where('id', $subscriptionId)->update([
            'current_transaction_id' => $info->currentTransactionId,
            'status' => $info->status,
            'starts_at' => $info->startsAt->format('Y-m-d H:i:s'),
            'expires_at' => $info->expiresAt->format('Y-m-d H:i:s'),
            'auto_renewing' => $info->autoRenewing,
            'updated_at' => now(),
        ]);
    }

    private function toDto(object $row): SubscriptionData
    {
        return new SubscriptionData(
            id: $row->id,
            userId: $row->user_id,
            productId: $row->product_id,
            platform: Platform::from($row->platform),
            originalTransactionId: $row->original_transaction_id,
            currentTransactionId: $row->current_transaction_id,
            storeProductId: $row->google_product_id ?? $row->product_product_id ?? '',
            status: SubscriptionStatus::from($row->status),
            expiresAt: new DateTimeImmutable($row->expires_at),
        );
    }
}
