<?php

declare(strict_types=1);

namespace App;

use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;
use Abante4wd\Iap\DTO\ProductData;
use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;
use Abante4wd\Iap\Enums\PurchaseType;

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
            verifiedAt: new \DateTimeImmutable,
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
        $purchase = new PurchaseData(
            id: $this->nextId++,
            userId: $userId,
            productId: $productId,
            platform: $platform,
            storeTransactionId: $txId,
            purchaseToken: $token,
            status: PurchaseStatus::Deferred,
            receiptPayload: $receipt,
            storeResponse: $response,
            pendingReason: $reason,
            deferredAt: new \DateTimeImmutable,
        );

        $this->purchases[$purchase->id] = $purchase;
        echo "  [PurchaseRepo] Purchase #{$purchase->id} created as pending ({$reason->value})\n";

        return $purchase;
    }

    public function findPendingByPlatformAndToken(Platform $platform, string $token): ?PurchaseData
    {
        foreach ($this->purchases as $purchase) {
            if ($purchase->platform === $platform
                && $purchase->purchaseToken === $token
                && $purchase->status === PurchaseStatus::Deferred) {
                return $purchase;
            }
        }

        return null;
    }

    public function findAllPendingByPlatform(Platform $platform): array
    {
        $result = [];
        foreach ($this->purchases as $purchase) {
            if ($purchase->platform === $platform
                && $purchase->status === PurchaseStatus::Deferred) {
                $result[] = $purchase;
            }
        }

        return $result;
    }

    public function completePending(int|string $purchaseId, string $txId, array $response): PurchaseData
    {
        $existing = $this->purchases[$purchaseId] ?? null;
        if (! $existing) {
            throw new \RuntimeException("Purchase #{$purchaseId} not found");
        }

        $purchase = new PurchaseData(
            id: $existing->id,
            userId: $existing->userId,
            productId: $existing->productId,
            platform: $existing->platform,
            storeTransactionId: $txId,
            purchaseToken: $existing->purchaseToken,
            status: PurchaseStatus::Verified,
            receiptPayload: $existing->receiptPayload,
            storeResponse: $response,
            verifiedAt: new \DateTimeImmutable,
            pendingReason: $existing->pendingReason,
            deferredAt: $existing->deferredAt,
            completedAt: new \DateTimeImmutable,
        );

        $this->purchases[$purchaseId] = $purchase;
        echo "  [PurchaseRepo] Purchase #{$purchaseId} completed (was pending)\n";

        return $purchase;
    }

    public function cancelPending(int|string $purchaseId, ?string $reason = null): void
    {
        $existing = $this->purchases[$purchaseId] ?? null;
        if (! $existing) {
            return;
        }

        $purchase = new PurchaseData(
            id: $existing->id,
            userId: $existing->userId,
            productId: $existing->productId,
            platform: $existing->platform,
            storeTransactionId: $existing->storeTransactionId,
            purchaseToken: $existing->purchaseToken,
            status: PurchaseStatus::Cancelled,
            receiptPayload: $existing->receiptPayload,
            storeResponse: $existing->storeResponse,
            pendingReason: $existing->pendingReason,
            deferredAt: $existing->deferredAt,
        );

        $this->purchases[$purchaseId] = $purchase;
        echo "  [PurchaseRepo] Purchase #{$purchaseId} cancelled".($reason ? " (reason: {$reason})" : '')."\n";
    }

    public function transaction(callable $callback): mixed
    {
        return $callback();
    }
}
