<?php

namespace Abante4wd\Iap\Tests\Store;

use Abante4wd\Iap\Store\Config\GoogleConfig;
use Abante4wd\Iap\Store\GooglePlayVerifier;
use Google\Service\AndroidPublisher;
use PHPUnit\Framework\TestCase;

class GooglePlayVerifierTest extends TestCase
{
    private function makeConfig(): GoogleConfig
    {
        return new GoogleConfig(
            applicationName: 'Test App',
            serviceAccountJsonPath: '/nonexistent/service-account.json',
            packageName: 'com.example.app',
        );
    }

    public function test_constructor_accepts_injected_publisher(): void
    {
        $publisher = $this->createMock(AndroidPublisher::class);

        $verifier = new GooglePlayVerifier($this->makeConfig(), $publisher);

        $this->assertInstanceOf(GooglePlayVerifier::class, $verifier);
    }
}
