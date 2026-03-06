<?php

namespace Fukazawa\Iap\Store;

use Fukazawa\Iap\Contracts\StoreVerifierInterface;
use Fukazawa\Iap\DTO\SubscriptionInfo;
use Fukazawa\Iap\DTO\VerificationResult;
use Fukazawa\Iap\Store\Config\AppleConfig;
use Firebase\JWT\JWT;
use GuzzleHttp\Client;

class AppleStoreVerifier implements StoreVerifierInterface
{
    private string $baseUrl;
    private Client $httpClient;

    public function __construct(
        private AppleConfig $config,
        ?Client $httpClient = null,
    ) {
        $this->baseUrl = $this->config->environment === 'production'
            ? 'https://api.storekit.itunes.apple.com'
            : 'https://api.storekit-sandbox.itunes.apple.com';

        $this->httpClient = $httpClient ?? new Client();
    }

    public function verifyProduct(string $productId, string $purchaseToken, ?string $receiptData = null): VerificationResult
    {
        return $this->verifyTransaction($productId, $purchaseToken);
    }

    public function verifySubscription(string $productId, string $purchaseToken, ?string $receiptData = null): VerificationResult
    {
        return $this->verifyTransaction($productId, $purchaseToken, isSubscription: true);
    }

    public function acknowledge(string $productId, string $purchaseToken): bool
    {
        return true;
    }

    private function verifyTransaction(string $productId, string $transactionId, bool $isSubscription = false): VerificationResult
    {
        try {
            $token = $this->generateJwt();

            $response = $this->httpClient->get(
                "{$this->baseUrl}/inApps/v1/transactions/{$transactionId}",
                [
                    'headers' => [
                        'Authorization' => "Bearer {$token}",
                    ],
                    'http_errors' => false,
                ],
            );

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true) ?? [];

            if ($statusCode < 200 || $statusCode >= 300) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $transactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Apple API returned status: ' . $statusCode,
                );
            }

            $signedTransaction = $responseData['signedTransactionInfo'] ?? null;

            if (!$signedTransaction) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $transactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'No signed transaction info in response',
                );
            }

            $transactionPayload = $this->decodeJws($signedTransaction);

            if ($transactionPayload['bundleId'] !== $this->config->bundleId) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $transactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Bundle ID mismatch',
                );
            }

            $subscriptionInfo = null;
            if ($isSubscription) {
                $expiresDateMs = $transactionPayload['expiresDate'] ?? null;
                $expiresAt = $expiresDateMs
                    ? (new \DateTimeImmutable())->setTimestamp((int) ($expiresDateMs / 1000))
                    : new \DateTimeImmutable();

                $purchaseDateMs = $transactionPayload['originalPurchaseDate']
                    ?? $transactionPayload['purchaseDate'];

                $subscriptionInfo = new SubscriptionInfo(
                    originalTransactionId: (string) ($transactionPayload['originalTransactionId'] ?? $transactionId),
                    currentTransactionId: (string) ($transactionPayload['transactionId'] ?? $transactionId),
                    startsAt: (new \DateTimeImmutable())->setTimestamp((int) ($purchaseDateMs / 1000)),
                    expiresAt: $expiresAt,
                    autoRenewing: !($transactionPayload['isUpgraded'] ?? false),
                    status: $expiresAt > new \DateTimeImmutable() ? 'active' : 'expired',
                );
            }

            return new VerificationResult(
                isValid: true,
                transactionId: (string) ($transactionPayload['transactionId'] ?? $transactionId),
                productId: $transactionPayload['productId'] ?? $productId,
                rawResponse: $responseData,
                subscriptionInfo: $subscriptionInfo,
            );
        } catch (\Exception $e) {
            return new VerificationResult(
                isValid: false,
                transactionId: $transactionId,
                productId: $productId,
                rawResponse: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    private function generateJwt(): string
    {
        $privateKey = file_get_contents($this->config->privateKeyPath);

        $payload = [
            'iss' => $this->config->issuerId,
            'iat' => time(),
            'exp' => time() + 3600,
            'aud' => 'appstoreconnect-v1',
            'bid' => $this->config->bundleId,
        ];

        return JWT::encode($payload, $privateKey, 'ES256', $this->config->keyId);
    }

    private function decodeJws(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWS format');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (!$payload) {
            throw new \RuntimeException('Failed to decode JWS payload');
        }

        return $payload;
    }
}
