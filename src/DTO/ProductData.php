<?php

namespace Fukazawa\Iap\DTO;

use Fukazawa\Iap\Enums\PurchaseType;

readonly class ProductData
{
    public function __construct(
        public int|string $id,
        public string $productId,
        public string $name,
        public PurchaseType $type,
        public ?string $googleProductId = null,
        public ?string $appleProductId = null,
    ) {}

    public function storeProductId(string $platform): string
    {
        return match ($platform) {
            'google' => $this->googleProductId ?? $this->productId,
            'apple' => $this->appleProductId ?? $this->productId,
        };
    }
}
