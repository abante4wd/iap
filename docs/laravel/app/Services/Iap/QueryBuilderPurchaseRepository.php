<?php

declare(strict_types=1);

namespace App\Services\Iap;

use Fukazawa\Iap\Contracts\PurchaseRepositoryInterface;
use Fukazawa\Iap\DTO\ProductData;
use Fukazawa\Iap\DTO\PurchaseData;
use Fukazawa\Iap\Enums\PendingReason;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\PurchaseStatus;
use Fukazawa\Iap\Enums\PurchaseType;
use Illuminate\Support\Facades\DB;

class QueryBuilderPurchaseRepository implements PurchaseRepositoryInterface
{
    public function findProductByProductId(string $productId): ?ProductData
    {
        $row = DB::table('products')->where('product_id', $productId)->first();

        if (! $row) {
            return null;
        }

        return new ProductData(
            id: $row->id,
            productId: $row->product_id,
            name: $row->name,
            type: PurchaseType::from($row->type),
            googleProductId: $row->google_product_id,
            appleProductId: $row->apple_product_id,
        );
    }

    public function findVerifiedByPlatformAndTransactionId(Platform $platform, string $txId): ?PurchaseData
    {
        $row = DB::table('purchases')
            ->where('platform', $platform->value)
            ->where('transaction_id', $txId)
            ->where('status', PurchaseStatus::Verified->value)
            ->first();

        if (! $row) {
            return null;
        }

        return $this->toDto($row);
    }

    public function createFailed(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
    ): PurchaseData {
        $id = DB::table('purchases')->insertGetId([
            'user_id' => $userId,
            'product_id' => $productId,
            'platform' => $platform->value,
            'transaction_id' => $txId,
            'purchase_token' => $token,
            'status' => PurchaseStatus::Failed->value,
            'receipt_payload' => $receipt,
            'store_response' => json_encode($response),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->toDto(DB::table('purchases')->find($id));
    }

    public function createOrUpdateVerified(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
    ): PurchaseData {
        $existing = DB::table('purchases')
            ->where('platform', $platform->value)
            ->where('transaction_id', $txId)
            ->first();

        $data = [
            'user_id' => $userId,
            'product_id' => $productId,
            'purchase_token' => $token,
            'status' => PurchaseStatus::Verified->value,
            'receipt_payload' => $receipt,
            'store_response' => json_encode($response),
            'verified_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('purchases')->where('id', $existing->id)->update($data);
            $id = $existing->id;
        } else {
            $id = DB::table('purchases')->insertGetId(array_merge($data, [
                'platform' => $platform->value,
                'transaction_id' => $txId,
                'created_at' => now(),
            ]));
        }

        return $this->toDto(DB::table('purchases')->find($id));
    }

    public function markAcknowledged(int|string $purchaseId): void
    {
        DB::table('purchases')->where('id', $purchaseId)->update([
            'acknowledged_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function markRewardsGranted(int|string $purchaseId): void
    {
        DB::table('purchases')->where('id', $purchaseId)->update([
            'rewards_granted_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function createOrUpdatePending(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
        PendingReason $reason,
    ): PurchaseData {
        $existing = DB::table('purchases')
            ->where('platform', $platform->value)
            ->where('purchase_token', $token)
            ->where('status', PurchaseStatus::Deferred->value)
            ->first();

        $data = [
            'user_id' => $userId,
            'product_id' => $productId,
            'transaction_id' => $txId,
            'receipt_payload' => $receipt,
            'store_response' => json_encode($response),
            'pending_reason' => $reason->value,
            'deferred_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('purchases')->where('id', $existing->id)->update($data);
            $id = $existing->id;
        } else {
            $id = DB::table('purchases')->insertGetId(array_merge($data, [
                'platform' => $platform->value,
                'purchase_token' => $token,
                'status' => PurchaseStatus::Deferred->value,
                'created_at' => now(),
            ]));
        }

        return $this->toDto(DB::table('purchases')->find($id));
    }

    public function findPendingByPlatformAndToken(Platform $platform, string $token): ?PurchaseData
    {
        $row = DB::table('purchases')
            ->where('platform', $platform->value)
            ->where('purchase_token', $token)
            ->where('status', PurchaseStatus::Deferred->value)
            ->first();

        if (! $row) {
            return null;
        }

        return $this->toDto($row);
    }

    public function findAllPendingByPlatform(Platform $platform): array
    {
        $rows = DB::table('purchases')
            ->where('platform', $platform->value)
            ->where('status', PurchaseStatus::Deferred->value)
            ->get();

        return array_map(
            fn (object $row) => $this->toDto($row),
            $rows->all(),
        );
    }

    public function completePending(int|string $purchaseId, string $txId, array $response): PurchaseData
    {
        DB::table('purchases')->where('id', $purchaseId)->update([
            'transaction_id' => $txId,
            'status' => PurchaseStatus::Verified->value,
            'store_response' => json_encode($response),
            'verified_at' => now(),
            'completed_at' => now(),
            'updated_at' => now(),
        ]);

        return $this->toDto(DB::table('purchases')->find($purchaseId));
    }

    public function cancelPending(int|string $purchaseId, ?string $reason = null): void
    {
        DB::table('purchases')->where('id', $purchaseId)->update([
            'status' => PurchaseStatus::Cancelled->value,
            'updated_at' => now(),
        ]);
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    private function toDto(object $row): PurchaseData
    {
        return new PurchaseData(
            id: $row->id,
            userId: $row->user_id,
            productId: $row->product_id,
            platform: Platform::from($row->platform),
            storeTransactionId: $row->transaction_id,
            purchaseToken: $row->purchase_token,
            status: PurchaseStatus::from($row->status),
            receiptPayload: $row->receipt_payload,
            storeResponse: $row->store_response ? json_decode($row->store_response, true) : null,
            verifiedAt: isset($row->verified_at) ? new \DateTimeImmutable($row->verified_at) : null,
            acknowledgedAt: isset($row->acknowledged_at) ? new \DateTimeImmutable($row->acknowledged_at) : null,
            rewardsGrantedAt: isset($row->rewards_granted_at) ? new \DateTimeImmutable($row->rewards_granted_at) : null,
            pendingReason: isset($row->pending_reason) ? PendingReason::from($row->pending_reason) : null,
            deferredAt: isset($row->deferred_at) ? new \DateTimeImmutable($row->deferred_at) : null,
            completedAt: isset($row->completed_at) ? new \DateTimeImmutable($row->completed_at) : null,
        );
    }
}
