<?php

namespace Fukazawa\Iap\Contracts;

use Fukazawa\Iap\DTO\ProductData;
use Fukazawa\Iap\DTO\PurchaseData;
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

    public function transaction(callable $callback): mixed;
}
