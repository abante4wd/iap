<?php

namespace Abante4wd\Iap\Tests\Store;

use Abante4wd\Iap\Store\AppleStoreVerifier;
use Abante4wd\Iap\Store\Config\AppleConfig;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

class AppleStoreVerifierTest extends TestCase
{
    private AppleConfig $config;
    private ?string $keyFile = null;
    private AppleConfig $configWithKey;

    protected function setUp(): void
    {
        $this->config = new AppleConfig(
            bundleId: 'com.example.myapp',
            issuerId: '00000000-0000-0000-0000-000000000000',
            keyId: 'TESTKEY123',
            privateKeyPath: '/nonexistent/key.p8',
            environment: 'sandbox',
        );

        $keyPair = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        $this->keyFile = tempnam(sys_get_temp_dir(), 'test_apple_key_');
        openssl_pkey_export_to_file($keyPair, $this->keyFile);
        $this->configWithKey = new AppleConfig(
            bundleId: 'com.example.myapp',
            issuerId: '00000000-0000-0000-0000-000000000000',
            keyId: 'TESTKEY123',
            privateKeyPath: $this->keyFile,
            environment: 'sandbox',
        );
    }

    protected function tearDown(): void
    {
        if ($this->keyFile !== null && file_exists($this->keyFile)) {
            unlink($this->keyFile);
        }
    }

    private function makeJws(array $payload): string
    {
        $headerB64 = rtrim(strtr(base64_encode(json_encode(['alg' => 'ES256', 'x5c' => []])), '+/', '-_'), '=');
        $payloadB64 = rtrim(strtr(base64_encode(json_encode($payload)), '+/', '-_'), '=');
        return $headerB64 . '.' . $payloadB64 . '.fakesig';
    }

    private function makeMockHttpClient(int $status, array $body): Client
    {
        $mock = new MockHandler([new Response($status, [], json_encode($body))]);
        return new Client(['handler' => HandlerStack::create($mock)]);
    }

    public function test_verifyProduct_returns_invalid_for_non_jws_string(): void
    {
        $verifier = new AppleStoreVerifier($this->config);
        $result = $verifier->verifyProduct('prod1', 'not-a-jws-token');

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Invalid JWS', $result->errorMessage);
    }

    public function test_verifyProduct_returns_invalid_when_jws_missing_transaction_id(): void
    {
        $jws = $this->makeJws(['productId' => 'com.example.item']);
        $verifier = new AppleStoreVerifier($this->config);
        $result = $verifier->verifyProduct('prod1', $jws);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('transactionId', $result->errorMessage);
    }

    public function test_verifyProduct_returns_valid_on_success(): void
    {
        $transactionId = '999888777';
        $inputJws = $this->makeJws(['transactionId' => $transactionId]);

        $responsePayload = [
            'transactionId' => $transactionId,
            'productId' => 'com.example.myapp.gem100',
            'bundleId' => 'com.example.myapp',
        ];
        $signedTransactionInfo = $this->makeJws($responsePayload);

        $httpClient = $this->makeMockHttpClient(200, ['signedTransactionInfo' => $signedTransactionInfo]);

        $verifier = new TestableAppleStoreVerifier($this->configWithKey, $httpClient);
        $verifier->jwsPayloadToReturn = $responsePayload;

        $result = $verifier->verifyProduct('prod1', $inputJws);

        $this->assertTrue($result->isValid);
        $this->assertSame($transactionId, $result->transactionId);
        $this->assertSame('com.example.myapp.gem100', $result->productId);
    }

    public function test_verifyProduct_returns_invalid_on_apple_api_error(): void
    {
        $inputJws = $this->makeJws(['transactionId' => '111222333']);

        $httpClient = $this->makeMockHttpClient(500, ['errorCode' => 4290000]);
        $verifier = new TestableAppleStoreVerifier($this->configWithKey, $httpClient);

        $result = $verifier->verifyProduct('prod1', $inputJws);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('500', $result->errorMessage);
    }

    public function test_verifyProduct_returns_pending_on_404_with_client_reports_pending(): void
    {
        $inputJws = $this->makeJws(['transactionId' => '444555666']);

        $httpClient = $this->makeMockHttpClient(404, []);
        $verifier = new TestableAppleStoreVerifier($this->configWithKey, $httpClient);

        $result = $verifier->verifyProduct('prod1', $inputJws, clientReportsPending: true);

        $this->assertFalse($result->isValid);
        $this->assertTrue($result->isPending);
    }

    public function test_verifyProduct_returns_invalid_when_jws_signature_fails(): void
    {
        $inputJws = $this->makeJws(['transactionId' => '12345']);
        $httpClient = $this->makeMockHttpClient(200, [
            'signedTransactionInfo' => $this->makeJws([
                'transactionId' => '12345',
                'productId' => 'com.example.myapp.gem100',
                'bundleId' => 'com.example.myapp',
            ]),
        ]);

        $verifier = new TestableAppleStoreVerifier($this->configWithKey, $httpClient);
        $verifier->throwOnVerify = true;

        $result = $verifier->verifyProduct('prod1', $inputJws);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('signature', $result->errorMessage);
    }

    public function test_verifySubscription_reads_autoRenewStatus_from_renewalInfo(): void
    {
        $transactionId = '111222333';
        $inputJws = $this->makeJws(['transactionId' => $transactionId]);

        $transactionPayload = [
            'transactionId' => $transactionId,
            'originalTransactionId' => $transactionId,
            'productId' => 'com.example.myapp.premium',
            'bundleId' => 'com.example.myapp',
            'purchaseDate' => 1700000000000,
            'expiresDate' => (time() + 3600) * 1000,
            'environment' => 'Sandbox',
        ];
        $renewalPayload = [
            'autoRenewStatus' => 0,   // 自動更新オフ
            'isInBillingRetryPeriod' => true,
        ];
        $signedTransactionInfo = $this->makeJws($transactionPayload);
        $signedRenewalInfo = $this->makeJws($renewalPayload);

        $httpClient = $this->makeMockHttpClient(200, [
            'signedTransactionInfo' => $signedTransactionInfo,
            'signedRenewalInfo' => $signedRenewalInfo,
        ]);

        $verifier = new TestableAppleStoreVerifier($this->configWithKey, $httpClient);
        $verifier->jwsPayloads = [
            $signedTransactionInfo => $transactionPayload,
            $signedRenewalInfo => $renewalPayload,
        ];

        $result = $verifier->verifySubscription('prod1', $inputJws);

        $this->assertTrue($result->isValid);
        $this->assertNotNull($result->subscriptionInfo);
        $this->assertFalse($result->subscriptionInfo->autoRenewing);
        $this->assertTrue($result->subscriptionInfo->isInBillingRetry);
    }

    public function test_verifyProduct_returns_invalid_on_environment_mismatch(): void
    {
        $transactionId = '777888999';
        $inputJws = $this->makeJws(['transactionId' => $transactionId]);

        $transactionPayload = [
            'transactionId' => $transactionId,
            'productId' => 'com.example.myapp.gem100',
            'bundleId' => 'com.example.myapp',
            'environment' => 'Production',  // config は 'sandbox' なのでミスマッチ
        ];
        $signedTransactionInfo = $this->makeJws($transactionPayload);

        $httpClient = $this->makeMockHttpClient(200, ['signedTransactionInfo' => $signedTransactionInfo]);

        $verifier = new TestableAppleStoreVerifier($this->configWithKey, $httpClient);
        $verifier->jwsPayloads = [$signedTransactionInfo => $transactionPayload];

        $result = $verifier->verifyProduct('prod1', $inputJws);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('Environment mismatch', $result->errorMessage);
    }
}

class TestableAppleStoreVerifier extends AppleStoreVerifier
{
    public ?array $jwsPayloadToReturn = null;
    /** @var array<string, array> JWSトークン => デコード済みペイロード のマップ */
    public array $jwsPayloads = [];
    public bool $throwOnVerify = false;

    protected function verifyJwsSignature(string $jws): array
    {
        if ($this->throwOnVerify) {
            throw new \RuntimeException('JWS signature verification failed');
        }
        if (isset($this->jwsPayloads[$jws])) {
            return $this->jwsPayloads[$jws];
        }
        return $this->jwsPayloadToReturn ?? [];
    }
}
