<?php

declare(strict_types=1);

namespace App;

use Fukazawa\Iap\Contracts\PurchaseRepositoryInterface;
use Fukazawa\Iap\DTO\ProductData;
use Fukazawa\Iap\DTO\PurchaseData;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\PurchaseStatus;
use Fukazawa\Iap\Enums\PurchaseType;

class InMemoryPurchaseRepository implements PurchaseRepositoryInterface
{
    /** @var array<string, ProductData> */
    private array $products = [];

    /** @var array<int, PurchaseData> */
    private array $purchases = [];

    private int $nextId = 1;

    public function __construct()
    {
        $this->products['gem_100'] = new ProductData(
            id: 1,
            productId: 'gem_100',
            name: 'ジェム100個パック',
            type: PurchaseType::Consumable,
            googleProductId: 'com.example.myapp.gem_100',
            appleProductId: 'com.example.myapp.gem100',
        );

        $this->products['monthly_premium'] = new ProductData(
            id: 2,
            productId: 'monthly_premium',
            name: '月額プレミアムプラン',
            type: PurchaseType::Subscription,
            googleProductId: 'com.example.myapp.monthly_premium',
            appleProductId: 'com.example.myapp.monthly.premium',
        );
    }

    public function findProductByProductId(string $productId): ?ProductData
    {
        return $this->products[$productId] ?? null;
    }

    public function findVerifiedByPlatformAndTransactionId(Platform $platform, string $txId): ?PurchaseData
    {
        foreach ($this->purchases as $purchase) {
            if ($purchase->platform === $platform
                && $purchase->storeTransactionId === $txId
                && $purchase->status === PurchaseStatus::Verified) {
                return $purchase;
            }
        }

        return null;
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
        $purchase = new PurchaseData(
            id: $this->nextId++,
            userId: $userId,
            productId: $productId,
            platform: $platform,
            storeTransactionId: $txId,
            purchaseToken: $token,
            status: PurchaseStatus::Failed,
            receiptPayload: $receipt,
            storeResponse: $response,
        );

        $this->purchases[$purchase->id] = $purchase;

        return $purchase;
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
        $purchase = new PurchaseData(
            id: $this->nextId++,
            userId: $userId,
            productId: $productId,
            platform: $platform,
            storeTransactionId: $txId,
            purchaseToken: $token,
            status: PurchaseStatus::Verified,
            receiptPayload: $receipt,
            storeResponse: $response,
            verifiedAt: new \DateTimeImmutable(),
        );

        $this->purchases[$purchase->id] = $purchase;

        return $purchase;
    }

    public function markAcknowledged(int|string $purchaseId): void
    {
        echo "  [PurchaseRepo] Purchase #{$purchaseId} acknowledged\n";
    }

    public function markRewardsGranted(int|string $purchaseId): void
    {
        echo "  [PurchaseRepo] Purchase #{$purchaseId} rewards granted\n";
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }
}
