<?php

namespace Abante4wd\Iap\Contracts;

use Abante4wd\Iap\DTO\PurchaseData;

interface RewardGrantServiceInterface
{
    /**
     * @return array<int, array{type: string, identifier: string|null, quantity: int}>
     */
    public function grant(PurchaseData $purchase): array;
}
