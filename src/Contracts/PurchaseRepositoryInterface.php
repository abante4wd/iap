<?php

namespace Fukazawa\Iap\Contracts;

use Fukazawa\Iap\DTO\ProductData;
use Fukazawa\Iap\DTO\PurchaseData;
use Fukazawa\Iap\Enums\PendingReason;
use Fukazawa\Iap\Enums\Platform;

interface PurchaseRepositoryInterface
{
    public function findProductByProductId(string $productId): ?ProductData;

    public function findVerifiedByPlatformAndTransactionId(Platform $platform, string $txId): ?PurchaseData;

    public function createFailed(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
    ): PurchaseData;

    public function createOrUpdateVerified(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
    ): PurchaseData;

    public function markAcknowledged(int|string $purchaseId): void;

    public function markRewardsGranted(int|string $purchaseId): void;

    public function createOrUpdatePending(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
        PendingReason $reason,
    ): PurchaseData;

    public function findPendingByPlatformAndToken(Platform $platform, string $token): ?PurchaseData;

    /** @return PurchaseData[] */
    public function findAllPendingByPlatform(Platform $platform): array;

    public function completePending(int|string $purchaseId, string $txId, array $response): PurchaseData;

    public function cancelPending(int|string $purchaseId, ?string $reason = null): void;

    public function transaction(callable $callback): mixed;
}
