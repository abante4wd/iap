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
