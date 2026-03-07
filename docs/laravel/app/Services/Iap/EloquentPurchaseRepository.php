<?php

declare(strict_types=1);

namespace App\Services\Iap;

use App\Models\Product;
use App\Models\Purchase;
use Fukazawa\Iap\Contracts\PurchaseRepositoryInterface;
use Fukazawa\Iap\DTO\ProductData;
use Fukazawa\Iap\DTO\PurchaseData;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\PurchaseStatus;
use Fukazawa\Iap\Enums\PurchaseType;
use Illuminate\Support\Facades\DB;

class EloquentPurchaseRepository implements PurchaseRepositoryInterface
{
    public function findProductByProductId(string $productId): ?ProductData
    {
        $product = Product::where('product_id', $productId)->first();

        if (!$product) {
            return null;
        }

        return new ProductData(
            id: $product->id,
            productId: $product->product_id,
            name: $product->name,
            type: PurchaseType::from($product->type),
            googleProductId: $product->google_product_id,
            appleProductId: $product->apple_product_id,
        );
    }

    public function findVerifiedByPlatformAndTransactionId(Platform $platform, string $txId): ?PurchaseData
    {
        $purchase = Purchase::where('platform', $platform->value)
            ->where('transaction_id', $txId)
            ->where('status', PurchaseStatus::Verified->value)
            ->first();

        if (!$purchase) {
            return null;
        }

        return $this->toDto($purchase);
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
        $purchase = Purchase::create([
            'user_id' => $userId,
            'product_id' => $productId,
            'platform' => $platform->value,
            'transaction_id' => $txId,
            'purchase_token' => $token,
            'status' => PurchaseStatus::Failed->value,
            'receipt_payload' => $receipt,
            'store_response' => $response,
        ]);

        return $this->toDto($purchase);
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
        $purchase = Purchase::updateOrCreate(
            [
                'platform' => $platform->value,
                'transaction_id' => $txId,
            ],
            [
                'user_id' => $userId,
                'product_id' => $productId,
                'purchase_token' => $token,
                'status' => PurchaseStatus::Verified->value,
                'receipt_payload' => $receipt,
                'store_response' => $response,
                'verified_at' => now(),
            ],
        );

        return $this->toDto($purchase);
    }

    public function markAcknowledged(int|string $purchaseId): void
    {
        Purchase::where('id', $purchaseId)->update(['acknowledged_at' => now()]);
    }

    public function markRewardsGranted(int|string $purchaseId): void
    {
        Purchase::where('id', $purchaseId)->update(['rewards_granted_at' => now()]);
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    private function toDto(Purchase $purchase): PurchaseData
    {
        return new PurchaseData(
            id: $purchase->id,
            userId: $purchase->user_id,
            productId: $purchase->product_id,
            platform: Platform::from($purchase->platform),
            storeTransactionId: $purchase->transaction_id,
            purchaseToken: $purchase->purchase_token,
            status: PurchaseStatus::from($purchase->status),
            receiptPayload: $purchase->receipt_payload,
            storeResponse: $purchase->store_response,
            verifiedAt: $purchase->verified_at ? \DateTimeImmutable::createFromMutable($purchase->verified_at) : null,
            acknowledgedAt: $purchase->acknowledged_at ? \DateTimeImmutable::createFromMutable($purchase->acknowledged_at) : null,
            rewardsGrantedAt: $purchase->rewards_granted_at ? \DateTimeImmutable::createFromMutable($purchase->rewards_granted_at) : null,
        );
    }
}
