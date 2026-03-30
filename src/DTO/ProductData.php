<?php

namespace Fukazawa\Iap\DTO;

use Fukazawa\Iap\Enums\Platform;
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

    public function storeProductId(Platform $platform): string
    {
        return match ($platform) {
            Platform::Google => $this->googleProductId ?? $this->productId,
            Platform::Apple => $this->appleProductId ?? $this->productId,
        };
    }
}
