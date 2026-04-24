<?php

namespace Abante4wd\Iap\Store\Config;

readonly class AppleConfig
{
    public function __construct(
        public string $bundleId,
        public string $issuerId,
        public string $keyId,
        public string $privateKeyPath,
        public string $environment = 'sandbox',
    ) {}
}
