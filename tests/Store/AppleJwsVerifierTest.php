<?php

namespace Abante4wd\Iap\Tests\Store;

use Abante4wd\Iap\Store\AppleJwsVerifier;
use PHPUnit\Framework\TestCase;

class AppleJwsVerifierTest extends TestCase
{
    private AppleJwsVerifier $verifier;

    protected function setUp(): void
    {
        $this->verifier = new AppleJwsVerifier();
    }

    public function test_verify_throws_for_non_jws_string(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JWS format');
        $this->verifier->verify('not-a-jws');
    }

    public function test_verify_throws_for_jws_with_only_two_parts(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid JWS format');
        $this->verifier->verify('header.payload');
    }

    public function test_verify_throws_for_missing_x5c_header(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256'])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['foo' => 'bar'])), '+/', '-_'), '=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid certificate chain in JWS header');
        $this->verifier->verify($header . '.' . $payload . '.sig');
    }

    public function test_verify_throws_for_x5c_with_fewer_than_3_certs(): void
    {
        $header = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'x5c' => ['cert1', 'cert2']])), '+/', '-_'), '=');
        $payload = rtrim(strtr(base64_encode(json_encode(['foo' => 'bar'])), '+/', '-_'), '=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid certificate chain in JWS header');
        $this->verifier->verify($header . '.' . $payload . '.sig');
    }
}
