<?php

namespace Abante4wd\Iap\DTO;

use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\SubscriptionStatus;

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
