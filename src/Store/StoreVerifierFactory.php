<?php

namespace Abante4wd\Iap\Store;

use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\Enums\Platform;

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
