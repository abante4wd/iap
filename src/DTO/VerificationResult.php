<?php

namespace Fukazawa\Iap\DTO;

use Fukazawa\Iap\Enums\PendingReason;

class VerificationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly string $transactionId,
        public readonly string $productId,
        public readonly array $rawResponse,
        public readonly ?string $errorMessage = null,
        public readonly ?SubscriptionInfo $subscriptionInfo = null,
        public readonly bool $isPending = false,
        public readonly ?PendingReason $pendingReason = null,
    ) {}
}
