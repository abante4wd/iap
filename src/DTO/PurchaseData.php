<?php

namespace Abante4wd\Iap\DTO;

use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;

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
        public ?PendingReason $pendingReason = null,
        public ?\DateTimeImmutable $deferredAt = null,
        public ?\DateTimeImmutable $completedAt = null,
    ) {}
}
