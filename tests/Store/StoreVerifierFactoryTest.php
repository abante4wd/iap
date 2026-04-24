<?php

namespace Abante4wd\Iap\Tests\Store;

use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Store\AppleStoreVerifier;
use Abante4wd\Iap\Store\GooglePlayVerifier;
use Abante4wd\Iap\Store\StoreVerifierFactory;
use PHPUnit\Framework\TestCase;

class StoreVerifierFactoryTest extends TestCase
{
    public function test_make_returns_google_verifier_for_google_platform(): void
    {
        $googleVerifier = $this->createMock(GooglePlayVerifier::class);
        $appleVerifier = $this->createMock(AppleStoreVerifier::class);

        $factory = new StoreVerifierFactory($googleVerifier, $appleVerifier);

        $this->assertSame($googleVerifier, $factory->make(Platform::Google));
    }

    public function test_make_returns_apple_verifier_for_apple_platform(): void
    {
        $googleVerifier = $this->createMock(GooglePlayVerifier::class);
        $appleVerifier = $this->createMock(AppleStoreVerifier::class);

        $factory = new StoreVerifierFactory($googleVerifier, $appleVerifier);

        $this->assertSame($appleVerifier, $factory->make(Platform::Apple));
    }
}
