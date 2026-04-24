<?php

namespace Abante4wd\Iap\Store;

use Firebase\JWT\JWT;
use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\DTO\VerificationResult;
use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Store\Config\AppleConfig;
use GuzzleHttp\Client;

/**
 * Apple App Store (StoreKit 2) の購入・サブスクリプションを検証するクラス。
 *
 * Unity IAP v5 が返す jwsRepresentation を受け取り、内包する transactionId で
 * Apple App Store Server API を呼び出す。
 * API レスポンスの signedTransactionInfo は x5c 証明書チェーンと ECDSA 署名を
 * 検証した上でデコードするため、改ざんを検出できる。
 */
class AppleStoreVerifier implements StoreVerifierInterface
{
    private string $baseUrl;

    private Client $httpClient;

    /**
     * @param AppleConfig       $config     App Store 接続設定
     * @param Client|null       $httpClient HTTP クライアント（省略時は新規インスタンスを生成）
     * @param AppleJwsVerifier  $jwsVerifier JWS 検証クラス（省略時は新規インスタンスを生成）
     */
    public function __construct(
        private AppleConfig $config,
        ?Client $httpClient = null,
        private AppleJwsVerifier $jwsVerifier = new AppleJwsVerifier(),
    ) {
        $this->baseUrl = $this->config->environment === 'production'
            ? 'https://api.storekit.itunes.apple.com'
            : 'https://api.storekit-sandbox.itunes.apple.com';

        $this->httpClient = $httpClient ?? new Client;
    }

    /**
     * 単品・消耗品購入を App Store Server API で検証する。
     *
     * @param string      $productId            App Store の商品 ID
     * @param string      $purchaseToken        Unity IAP v5 が返す jwsRepresentation
     * @param string|null $receiptData          未使用（StoreKit 2 では不要）
     * @param bool|null   $clientReportsPending true の場合、404 を Ask to Buy 保留として扱う
     * @return VerificationResult 検証結果
     */
    public function verifyProduct(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult
    {
        return $this->verifyTransaction($productId, $purchaseToken, clientReportsPending: $clientReportsPending);
    }

    /**
     * サブスクリプション購入を App Store Server API で検証する。
     *
     * @param string      $productId            App Store のサブスクリプション商品 ID
     * @param string      $purchaseToken        Unity IAP v5 が返す jwsRepresentation
     * @param string|null $receiptData          未使用（StoreKit 2 では不要）
     * @param bool|null   $clientReportsPending true の場合、404 を Ask to Buy 保留として扱う
     * @return VerificationResult 検証結果（有効な場合は subscriptionInfo を含む）
     */
    public function verifySubscription(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult
    {
        return $this->verifyTransaction($productId, $purchaseToken, isSubscription: true, clientReportsPending: $clientReportsPending);
    }

    /**
     * App Store は Acknowledge 不要のため常に true を返す。
     *
     * @param string $productId     未使用
     * @param string $purchaseToken 未使用
     * @return bool 常に true
     */
    public function acknowledge(string $productId, string $purchaseToken): bool
    {
        return true;
    }

    /**
     * jwsRepresentation から transactionId を抽出し、Apple API で検証して VerificationResult を返す。
     *
     * @param string    $productId            商品 ID
     * @param string    $jwsRepresentation    Unity IAP v5 が返す JWS トークン
     * @param bool      $isSubscription       true の場合はサブスクリプション情報を構築する
     * @param bool|null $clientReportsPending true の場合、404 を Ask to Buy 保留として扱う
     * @return VerificationResult 検証結果
     */
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

            // environment 検証
            $expectedEnv = $this->config->environment === 'production' ? 'Production' : 'Sandbox';
            $actualEnv = $transactionPayload['environment'] ?? 'unknown';
            if ($actualEnv !== $expectedEnv) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $transactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Environment mismatch: expected ' . $expectedEnv . ', got ' . $actualEnv,
                );
            }

            // signedRenewalInfo をデコード（存在する場合のみ）
            $renewalPayload = null;
            $signedRenewalInfo = $responseData['signedRenewalInfo'] ?? null;
            if ($signedRenewalInfo !== null) {
                try {
                    $renewalPayload = $this->verifyJwsSignature($signedRenewalInfo);
                } catch (\Exception) {
                    // renewalInfo の検証失敗はトランザクション検証を妨げない
                }
            }

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

                $autoRenewing = isset($renewalPayload['autoRenewStatus'])
                    ? ($renewalPayload['autoRenewStatus'] === 1)
                    : true;
                $isInBillingRetry = (bool) ($renewalPayload['isInBillingRetryPeriod'] ?? false);
                $gracePeriodMs = $renewalPayload['gracePeriodExpiresDate'] ?? null;
                $gracePeriodExpiresAt = $gracePeriodMs !== null
                    ? (new \DateTimeImmutable)->setTimestamp((int) ($gracePeriodMs / 1000))
                    : null;

                $subscriptionInfo = new SubscriptionInfo(
                    originalTransactionId: (string) ($transactionPayload['originalTransactionId'] ?? $transactionId),
                    currentTransactionId: (string) ($transactionPayload['transactionId'] ?? $transactionId),
                    startsAt: (new \DateTimeImmutable)->setTimestamp((int) ($purchaseDateMs / 1000)),
                    expiresAt: $expiresAt,
                    autoRenewing: $autoRenewing,
                    status: $expiresAt > new \DateTimeImmutable ? 'active' : 'expired',
                    isInBillingRetry: $isInBillingRetry,
                    gracePeriodExpiresAt: $gracePeriodExpiresAt,
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

    /**
     * Apple Subscriptions API でサブスクリプション最新状態を取得する。
     *
     * `/inApps/v1/subscriptions/{originalTransactionId}` を呼び出し、
     * 最新トランザクションの JWS を検証して SubscriptionInfo を構築する。
     *
     * @param string $originalTransactionId 元のトランザクション ID
     * @param string $productId             商品 ID
     * @return VerificationResult 検証結果
     */
    public function refreshSubscriptionStatus(string $originalTransactionId, string $productId): VerificationResult
    {
        try {
            $token = $this->generateJwt();

            $response = $this->httpClient->get(
                "{$this->baseUrl}/inApps/v1/subscriptions/{$originalTransactionId}",
                [
                    'headers' => ['Authorization' => "Bearer {$token}"],
                    'http_errors' => false,
                ],
            );

            $statusCode = $response->getStatusCode();
            $responseData = json_decode($response->getBody()->getContents(), true) ?? [];

            if ($statusCode < 200 || $statusCode >= 300) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $originalTransactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Apple Subscriptions API returned status: ' . $statusCode,
                );
            }

            $lastTx = $this->findLastTransaction($responseData, $originalTransactionId);
            if ($lastTx === null) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $originalTransactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'No matching transaction found in subscription response',
                );
            }

            // status: 1=Active, 2=Expired, 3=BillingRetry, 4=GracePeriod, 5=Revoked
            $txStatus = (int) ($lastTx['status'] ?? 0);
            $isValid = in_array($txStatus, [1, 3, 4], true);

            if (empty($lastTx['signedTransactionInfo'])) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $originalTransactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'No signedTransactionInfo in lastTransaction',
                );
            }

            $transactionPayload = $this->verifyJwsSignature($lastTx['signedTransactionInfo']);

            $expectedEnv = $this->config->environment === 'production' ? 'Production' : 'Sandbox';
            $actualEnv = $transactionPayload['environment'] ?? 'unknown';
            if ($actualEnv !== $expectedEnv) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $originalTransactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Environment mismatch: expected ' . $expectedEnv . ', got ' . $actualEnv,
                );
            }

            if ($transactionPayload['bundleId'] !== $this->config->bundleId) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $originalTransactionId,
                    productId: $productId,
                    rawResponse: $responseData,
                    errorMessage: 'Bundle ID mismatch',
                );
            }

            $renewalPayload = null;
            if (isset($lastTx['signedRenewalInfo'])) {
                try {
                    $renewalPayload = $this->verifyJwsSignature($lastTx['signedRenewalInfo']);
                } catch (\Exception) {
                    // renewalInfo の検証失敗はトランザクション検証を妨げない
                }
            }

            $expiresDateMs = $transactionPayload['expiresDate'] ?? null;
            $expiresAt = $expiresDateMs
                ? (new \DateTimeImmutable)->setTimestamp((int) ($expiresDateMs / 1000))
                : new \DateTimeImmutable;

            $purchaseDateMs = $transactionPayload['originalPurchaseDate'] ?? $transactionPayload['purchaseDate'] ?? 0;
            $autoRenewing = isset($renewalPayload['autoRenewStatus']) ? ($renewalPayload['autoRenewStatus'] === 1) : true;
            $isInBillingRetry = (bool) ($renewalPayload['isInBillingRetryPeriod'] ?? false);
            $gracePeriodMs = $renewalPayload['gracePeriodExpiresDate'] ?? null;
            $gracePeriodExpiresAt = $gracePeriodMs !== null
                ? (new \DateTimeImmutable)->setTimestamp((int) ($gracePeriodMs / 1000))
                : null;

            $statusStr = match ($txStatus) {
                1 => 'active',
                3 => 'billing_retry',
                4 => 'grace_period',
                5 => 'revoked',
                default => 'expired',
            };

            $subscriptionInfo = new SubscriptionInfo(
                originalTransactionId: (string) ($transactionPayload['originalTransactionId'] ?? $originalTransactionId),
                currentTransactionId: (string) ($transactionPayload['transactionId'] ?? $originalTransactionId),
                startsAt: (new \DateTimeImmutable)->setTimestamp((int) ($purchaseDateMs / 1000)),
                expiresAt: $expiresAt,
                autoRenewing: $autoRenewing,
                status: $statusStr,
                isInBillingRetry: $isInBillingRetry,
                gracePeriodExpiresAt: $gracePeriodExpiresAt,
            );

            return new VerificationResult(
                isValid: $isValid,
                transactionId: (string) ($transactionPayload['transactionId'] ?? $originalTransactionId),
                productId: $transactionPayload['productId'] ?? $productId,
                rawResponse: $responseData,
                subscriptionInfo: $subscriptionInfo,
            );
        } catch (\Exception $e) {
            return new VerificationResult(
                isValid: false,
                transactionId: $originalTransactionId,
                productId: $productId,
                rawResponse: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * レスポンスの `data` 配列から `originalTransactionId` に一致する最新トランザクションを探す。
     *
     * @param array  $responseData   API レスポンスの連想配列
     * @param string $originalTxId  探すトランザクション ID
     * @return array|null 見つかった場合はトランザクション配列、なければ null
     */
    private function findLastTransaction(array $responseData, string $originalTxId): ?array
    {
        foreach ($responseData['data'] ?? [] as $group) {
            foreach ($group['lastTransactions'] ?? [] as $lastTx) {
                if ((string) ($lastTx['originalTransactionId'] ?? '') === $originalTxId) {
                    return $lastTx;
                }
            }
        }
        return null;
    }

    /**
     * JWS の第2セグメント（ペイロード）を base64url デコードして transactionId を返す。
     *
     * Apple API が権威的に検証するため、ここでは署名検証を行わない。
     *
     * @param string $jws JWS 文字列（"header.payload.signature" 形式）
     * @return string トランザクション ID
     * @throws \RuntimeException JWS フォーマット不正または transactionId が存在しない場合
     */
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

    /**
     * App Store Server API 呼び出し用の JWT を生成する。
     *
     * ES256 アルゴリズムで署名し、有効期間は 1 時間に設定する。
     *
     * @return string Bearer トークン文字列
     * @throws \RuntimeException 秘密鍵ファイルの読み取りに失敗した場合
     */
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

    /**
     * JWS の x5c 証明書チェーンと ECDSA 署名を検証し、ペイロードを返す。
     *
     * @param string $jws 検証対象の JWS 文字列（"header.payload.signature" 形式）
     * @return array デコード済みのペイロード配列
     * @throws \RuntimeException 証明書チェーンまたは署名の検証に失敗した場合
     */
    protected function verifyJwsSignature(string $jws): array
    {
        return $this->jwsVerifier->verify($jws);
    }
}
