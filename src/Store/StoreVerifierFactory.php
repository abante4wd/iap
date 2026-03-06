<?php

namespace Fukazawa\Iap\Store;

use Fukazawa\Iap\Contracts\StoreVerifierInterface;
use Fukazawa\Iap\Enums\Platform;

class StoreVerifierFactory
{
    public function __construct(
        private GooglePlayVerifier $googleVerifier,
        private AppleStoreVerifier $appleVerifier,
    ) {}

    public function make(Platform $platform): StoreVerifierInterface
    {
        return match ($platform) {
            Platform::Google => $this->googleVerifier,
            Platform::Apple => $this->appleVerifier,
        };
    }
}
