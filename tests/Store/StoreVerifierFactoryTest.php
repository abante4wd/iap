<?php

namespace Fukazawa\Iap\Tests\Store;

use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Store\AppleStoreVerifier;
use Fukazawa\Iap\Store\GooglePlayVerifier;
use Fukazawa\Iap\Store\StoreVerifierFactory;
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
