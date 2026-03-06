<?php

namespace Fukazawa\Iap\Contracts;

use Fukazawa\Iap\DTO\PurchaseData;

interface RewardGrantServiceInterface
{
    /**
     * @return array<int, array{type: string, identifier: string|null, quantity: int}>
     */
    public function grant(PurchaseData $purchase): array;
}
