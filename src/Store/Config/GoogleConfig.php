<?php

namespace Abante4wd\Iap\Store\Config;

readonly class GoogleConfig
{
    public function __construct(
        public string $applicationName,
        public string $serviceAccountJsonPath,
        public string $packageName,
    ) {}
}
