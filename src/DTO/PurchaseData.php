<?php

namespace Fukazawa\Iap\DTO;

use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\PurchaseStatus;

readonly class PurchaseData
{
    public function __construct(
        public int|string $id,
        public int|string $userId,
        public int|string $productId,
        public Platform $platform,
        public string $storeTransactionId,
        public string $purchaseToken,
        public PurchaseStatus $status,
        public ?string $receiptPayload = null,
        public ?array $storeResponse = null,
        public ?\DateTimeImmutable $verifiedAt = null,
        public ?\DateTimeImmutable $acknowledgedAt = null,
        public ?\DateTimeImmutable $rewardsGrantedAt = null,
    ) {}
}
