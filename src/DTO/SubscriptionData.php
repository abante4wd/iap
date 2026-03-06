<?php

namespace Fukazawa\Iap\DTO;

use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\SubscriptionStatus;

readonly class SubscriptionData
{
    public function __construct(
        public int|string $id,
        public int|string $userId,
        public int|string $productId,
        public Platform $platform,
        public string $originalTransactionId,
        public string $currentTransactionId,
        public string $storeProductId,
        public SubscriptionStatus $status,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
