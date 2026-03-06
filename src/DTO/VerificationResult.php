<?php

namespace Fukazawa\Iap\DTO;

class VerificationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly string $transactionId,
        public readonly string $productId,
        public readonly array $rawResponse,
        public readonly ?string $errorMessage = null,
        public readonly ?SubscriptionInfo $subscriptionInfo = null,
    ) {}
}
