<?php

namespace Fukazawa\Iap\Contracts;

use Fukazawa\Iap\DTO\VerificationResult;

interface StoreVerifierInterface
{
    public function verifyProduct(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult;

    public function verifySubscription(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult;

    public function acknowledge(string $productId, string $purchaseToken): bool;
}
