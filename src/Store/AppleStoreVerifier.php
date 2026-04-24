<?php

namespace Abante4wd\Iap\Store;

use Firebase\JWT\JWT;
use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\DTO\VerificationResult;
use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Store\Config\AppleConfig;
use GuzzleHttp\Client;

class AppleStoreVerifier implements StoreVerifierInterface
{
    private const APPLE_ROOT_CA_G3 = <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICQzCCAcmgAwIBAgIILcX8iNLFS5UwCgYIKoZIzj0EAwMwZzEbMBkGA1UEAwwS
QXBwbGUgUm9vdCBDQSAtIEczMSYwJAYDVQQLDB1BcHBsZSBDZXJ0aWZpY2F0aW9u
IEF1dGhvcml0eTETMBEGA1UECgwKQXBwbGUgSW5jLjELMAkGA1UEBhMCVVMwHhcN
MTQwNDMwMTgxOTA2WhcNMzkwNDMwMTgxOTA2WjBnMRswGQYDVQQDDBJBcHBsZSBS
b290IENBIC0gRzMxJjAkBgNVBAsMHUFwcGxlIENlcnRpZmljYXRpb24gQXV0aG9y
aXR5MRMwEQYDVQQKDApBcHBsZSBJbmMuMQswCQYDVQQGEwJVUzB2MBAGByqGSM49
AgEGBSuBBAAiA2IABJjpLz1AcqTtkyJygRMc3RCV8cWjTnHcFBbZDuWmBSp3ZHtf
TjjTuxxEtX/1H7YyYl3J6YRbTzBPEVoA/VhYDKX1DyxNB0cTddqXl5dvMVztK517
IDvYuVTZXpmkOlEKMaNCMEAwHQYDVR0OBBYEFLuw3qFYM4iapIqZ3r6966/ayySr
MA8GA1UdEwEB/wQFMAMBAf8wDgYDVR0PAQH/BAQDAgEGMAoGCCqGSM49BAMDA2gA
MGUCMQCD6cHEFl4aXTQY2e3v9GwOAEZLuN+yRhHFD/3meoyhpmvOwgPUnPWTxnS4
at+qIxUCMG1mihDK1A3UT82NQz60imOlM27jbdoXt2QfyFMm+YhidDkLF1vLUagM
6BgD56KyKA==
-----END CERTIFICATE-----
PEM;

    private string $baseUrl;

    private Client $httpClient;

    public function __construct(
        private AppleConfig $config,
        ?Client $httpClient = null,
    ) {
        $this->baseUrl = $this->config->environment === 'production'
            ? 'https://api.storekit.itunes.apple.com'
            : 'https://api.storekit-sandbox.itunes.apple.com';

        $this->httpClient = $httpClient ?? new Client;
    }

    public function verifyProduct(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult
    {
        return $this->verifyTransaction($productId, $purchaseToken, clientReportsPending: $clientReportsPending);
    }

    public function verifySubscription(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult
    {
        return $this->verifyTransaction($productId, $purchaseToken, isSubscription: true, clientReportsPending: $clientReportsPending);
    }

    public function acknowledge(string $productId, string $purchaseToken): bool
    {
        return true;
    }

    private function verifyTransaction(string $productId, string $jwsRepresentation, bool $isSubscription = false, ?bool $clientReportsPending = null): VerificationResult
    {
        $transactionId = '';
        try {
            $transactionId = $this->extractTransactionIdFromJws($jwsRepresentation);
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
                // 404: トランザクションが見つからない場合、Ask to Buy で承認待ちの可能性がある
                if ($statusCode === 404 && $clientReportsPending === true) {
                    return new VerificationResult(
                        isValid: false,
                        transactionId: $transactionId,
                        productId: $productId,
                        rawResponse: $responseData,
                        isPending: true,
                        pendingReason: PendingReason::AskToBuy,
                    );
                }

                return new VerificationResult(
                    isValid: false,
                    transactionId: $transactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Apple API returned status: '.$statusCode,
                );
            }

            $signedTransaction = $responseData['signedTransactionInfo'] ?? null;

            if (! $signedTransaction) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $transactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'No signed transaction info in response',
                );
            }

            $transactionPayload = $this->verifyJwsSignature($signedTransaction);

            if ($transactionPayload['bundleId'] !== $this->config->bundleId) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $transactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Bundle ID mismatch',
                );
            }

            // revocationReason が存在する場合は取り消し済み
            if (isset($transactionPayload['revocationReason'])) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: (string) ($transactionPayload['transactionId'] ?? $transactionId),
                    productId: $transactionPayload['productId'] ?? $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Transaction has been revoked: reason='.$transactionPayload['revocationReason'],
                );
            }

            $subscriptionInfo = null;
            if ($isSubscription) {
                $expiresDateMs = $transactionPayload['expiresDate'] ?? null;
                $expiresAt = $expiresDateMs
                    ? (new \DateTimeImmutable)->setTimestamp((int) ($expiresDateMs / 1000))
                    : new \DateTimeImmutable;

                $purchaseDateMs = $transactionPayload['originalPurchaseDate']
                    ?? $transactionPayload['purchaseDate'];

                $subscriptionInfo = new SubscriptionInfo(
                    originalTransactionId: (string) ($transactionPayload['originalTransactionId'] ?? $transactionId),
                    currentTransactionId: (string) ($transactionPayload['transactionId'] ?? $transactionId),
                    startsAt: (new \DateTimeImmutable)->setTimestamp((int) ($purchaseDateMs / 1000)),
                    expiresAt: $expiresAt,
                    // expirationIntent が存在する場合は自動更新が無効化されている
                    autoRenewing: ! isset($transactionPayload['expirationIntent']),
                    status: $expiresAt > new \DateTimeImmutable ? 'active' : 'expired',
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

    private function extractTransactionIdFromJws(string $jws): string
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWS format');
        }

        $payload = json_decode(base64_decode(strtr($parts[1], '-_', '+/')), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Failed to decode JWS payload');
        }

        if (! isset($payload['transactionId'])) {
            throw new \RuntimeException('Missing transactionId in JWS payload');
        }

        return (string) $payload['transactionId'];
    }

    private function generateJwt(): string
    {
        $privateKey = file_get_contents($this->config->privateKeyPath);
        if ($privateKey === false) {
            throw new \RuntimeException("Failed to read private key file: {$this->config->privateKeyPath}");
        }

        $payload = [
            'iss' => $this->config->issuerId,
            'iat' => time(),
            'exp' => time() + 3600,
            'aud' => 'appstoreconnect-v1',
            'bid' => $this->config->bundleId,
        ];

        return JWT::encode($payload, $privateKey, 'ES256', $this->config->keyId);
    }

    protected function verifyJwsSignature(string $jws): array
    {
        $parts = explode('.', $jws);
        if (count($parts) !== 3) {
            throw new \RuntimeException('Invalid JWS format');
        }

        [$headerB64, $payloadB64, $signatureB64] = $parts;

        $header = json_decode(base64_decode(strtr($headerB64, '-_', '+/')), true);
        if (! is_array($header) || ! isset($header['x5c']) || count($header['x5c']) < 3) {
            throw new \RuntimeException('Invalid certificate chain in JWS header');
        }

        $certs = array_map(
            fn(string $base64Der) => "-----BEGIN CERTIFICATE-----\n" . chunk_split($base64Der, 64, "\n") . "-----END CERTIFICATE-----",
            $header['x5c']
        );

        // ルート証明書が Apple Root CA-G3 と一致するか確認
        $rootFingerprint = openssl_x509_fingerprint($certs[2], 'sha256');
        $appleRootFingerprint = openssl_x509_fingerprint(self::APPLE_ROOT_CA_G3, 'sha256');
        if ($rootFingerprint !== $appleRootFingerprint) {
            throw new \RuntimeException('Certificate chain root does not match Apple Root CA');
        }

        // 証明書チェーンを検証
        $rootKey = openssl_pkey_get_public($certs[2]);
        if ($rootKey === false) {
            throw new \RuntimeException('Failed to extract public key from root certificate');
        }

        $intermediateKey = openssl_pkey_get_public($certs[1]);
        if ($intermediateKey === false) {
            throw new \RuntimeException('Failed to extract public key from intermediate certificate');
        }

        if (openssl_x509_verify($certs[1], $rootKey) !== 1) {
            throw new \RuntimeException('Invalid certificate chain: intermediate not signed by root');
        }
        if (openssl_x509_verify($certs[0], $intermediateKey) !== 1) {
            throw new \RuntimeException('Invalid certificate chain: leaf not signed by intermediate');
        }

        // JWS 署名を検証（ES256: ECDSA P-256 + SHA-256）
        // JWS の ECDSA 署名は IEEE P1363 形式（R||S）のため DER に変換が必要
        $signingInput = $headerB64 . '.' . $payloadB64;
        $rawSignature = base64_decode(strtr($signatureB64, '-_', '+/'));
        $derSignature = $this->convertEcSignatureToDer($rawSignature);

        $leafKey = openssl_pkey_get_public($certs[0]);
        if ($leafKey === false) {
            throw new \RuntimeException('Failed to extract public key from leaf certificate');
        }
        if (openssl_verify($signingInput, $derSignature, $leafKey, OPENSSL_ALGO_SHA256) !== 1) {
            throw new \RuntimeException('JWS signature verification failed');
        }

        $payload = json_decode(base64_decode(strtr($payloadB64, '-_', '+/')), true);
        if (! is_array($payload)) {
            throw new \RuntimeException('Failed to decode JWS payload');
        }

        return $payload;
    }

    private function convertEcSignatureToDer(string $signature): string
    {
        if (strlen($signature) !== 64) {
            throw new \InvalidArgumentException(
                'Invalid ECDSA signature length: expected 64 bytes for ES256, got ' . strlen($signature)
            );
        }

        $len = 32;
        $r = ltrim(substr($signature, 0, $len), "\x00");
        $s = ltrim(substr($signature, $len), "\x00");

        if ($r === '' || ord($r[0]) >= 0x80) {
            $r = "\x00" . $r;
        }
        if ($s === '' || ord($s[0]) >= 0x80) {
            $s = "\x00" . $s;
        }

        $rLen = strlen($r);
        $sLen = strlen($s);

        return "\x30" . chr($rLen + $sLen + 4) . "\x02" . chr($rLen) . $r . "\x02" . chr($sLen) . $s;
    }
}
