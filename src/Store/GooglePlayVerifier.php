<?php

namespace Abante4wd\Iap\Store;

use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\DTO\VerificationResult;
use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Store\Config\GoogleConfig;
use Google\Client as GoogleClient;
use Google\Service\AndroidPublisher;

/**
 * Google Play の購入・サブスクリプションを検証するクラス。
 *
 * Google Play Developer API（AndroidPublisher）を使用して購入トークンの正当性を確認する。
 * サービスアカウント認証により直接 API を呼び出す。
 */
class GooglePlayVerifier implements StoreVerifierInterface
{
    /** @var AndroidPublisher Google Play Developer API クライアント */
    private AndroidPublisher $publisher;

    /**
     * @param GoogleConfig          $config    Google Play 接続設定
     * @param AndroidPublisher|null $publisher テスト用インジェクション（省略時は自動生成）
     */
    public function __construct(
        private GoogleConfig $config,
        ?AndroidPublisher $publisher = null,
    ) {
        if ($publisher !== null) {
            $this->publisher = $publisher;
            return;
        }
        $client = new GoogleClient;
        $client->setApplicationName($this->config->applicationName);
        $client->setAuthConfig($this->config->serviceAccountJsonPath);
        $client->addScope(AndroidPublisher::ANDROIDPUBLISHER);

        $this->publisher = new AndroidPublisher($client);
    }

    /**
     * 単品・消耗品購入を Google Play Developer API で検証する。
     *
     * purchaseState が 2（保留中）の場合は isPending=true を返す。
     * purchaseState が 0（購入済み）以外の場合は isValid=false を返す。
     *
     * @param string      $productId            Google Play の商品 ID
     * @param string      $purchaseToken        クライアントから受け取った購入トークン
     * @param string|null $receiptData          未使用（Google では不要）
     * @param bool|null   $clientReportsPending 未使用（Google の保留は API から判定）
     * @return VerificationResult 検証結果
     */
    public function verifyProduct(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult
    {
        try {
            $response = $this->publisher->purchases_products->get(
                $this->config->packageName,
                $productId,
                $purchaseToken,
            );

            $responseArray = $response->toSimpleObject() ? (array) $response->toSimpleObject() : [];

            // purchaseState: 0=purchased, 1=canceled, 2=pending
            if ($response->getPurchaseState() === 2) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $response->getOrderId() ?? '',
                    productId: $productId,
                    rawResponse: $responseArray,
                    isPending: true,
                    pendingReason: PendingReason::PendingPayment,
                );
            }

            if ($response->getPurchaseState() !== 0) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $response->getOrderId() ?? '',
                    productId: $productId,
                    rawResponse: $responseArray,
                    errorMessage: 'Purchase state is not purchased: '.$response->getPurchaseState(),
                );
            }

            return new VerificationResult(
                isValid: true,
                transactionId: $response->getOrderId(),
                productId: $productId,
                rawResponse: $responseArray,
            );
        } catch (\Exception $e) {
            return new VerificationResult(
                isValid: false,
                transactionId: '',
                productId: $productId,
                rawResponse: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * サブスクリプション購入を Google Play Developer API (v2) で検証する。
     *
     * SUBSCRIPTION_STATE_ACTIVE / SUBSCRIPTION_STATE_CANCELED /
     * SUBSCRIPTION_STATE_IN_GRACE_PERIOD のみ有効と判定する。
     * SubscriptionInfo を構築して VerificationResult に付与する。
     *
     * @param string      $productId            Google Play のサブスクリプション商品 ID
     * @param string      $purchaseToken        クライアントから受け取った購入トークン
     * @param string|null $receiptData          未使用
     * @param bool|null   $clientReportsPending 未使用
     * @return VerificationResult 検証結果（有効な場合は subscriptionInfo を含む）
     */
    public function verifySubscription(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult
    {
        try {
            $response = $this->publisher->purchases_subscriptionsv2->get(
                $this->config->packageName,
                $purchaseToken,
            );

            $responseArray = $response->toSimpleObject() ? (array) $response->toSimpleObject() : [];

            $lineItems = $response->getLineItems() ?? [];
            if (empty($lineItems)) {
                return new VerificationResult(
                    isValid: false,
                    transactionId: $response->getLatestOrderId() ?? '',
                    productId: $productId,
                    rawResponse: $responseArray,
                    errorMessage: 'No line items found in subscription response',
                );
            }
            $expiryTime = $lineItems[0]->getExpiryTime() ?? null;
            $autoRenewing = $lineItems[0]->getAutoRenewingPlan()?->getAutoRenewEnabled() ?? false;

            $subscriptionState = $response->getSubscriptionState();
            $status = match ($subscriptionState) {
                'SUBSCRIPTION_STATE_ACTIVE' => 'active',
                'SUBSCRIPTION_STATE_EXPIRED' => 'expired',
                'SUBSCRIPTION_STATE_CANCELED' => 'cancelled',
                'SUBSCRIPTION_STATE_IN_GRACE_PERIOD' => 'grace_period',
                'SUBSCRIPTION_STATE_ON_HOLD', 'SUBSCRIPTION_STATE_PAUSED' => 'paused',
                default => 'expired',
            };

            $startTime = $response->getStartTime();
            $latestOrderId = $response->getLatestOrderId() ?? '';

            $subscriptionInfo = new SubscriptionInfo(
                originalTransactionId: $purchaseToken,
                currentTransactionId: $latestOrderId,
                startsAt: $startTime !== null
                    ? new \DateTimeImmutable($startTime)
                    : new \DateTimeImmutable,
                expiresAt: $expiryTime ? new \DateTimeImmutable($expiryTime) : new \DateTimeImmutable,
                autoRenewing: $autoRenewing,
                status: $status,
            );

            return new VerificationResult(
                isValid: in_array($subscriptionState, [
                    'SUBSCRIPTION_STATE_ACTIVE',
                    'SUBSCRIPTION_STATE_CANCELED',
                    'SUBSCRIPTION_STATE_IN_GRACE_PERIOD',
                ], true),
                transactionId: $latestOrderId,
                productId: $productId,
                rawResponse: $responseArray,
                subscriptionInfo: $subscriptionInfo,
            );
        } catch (\Exception $e) {
            return new VerificationResult(
                isValid: false,
                transactionId: '',
                productId: $productId,
                rawResponse: [],
                errorMessage: $e->getMessage(),
            );
        }
    }

    /**
     * Google Play の消耗品購入を Acknowledge する。
     *
     * Acknowledge しない場合、購入から 3 日後に Google Play が自動的に返金する。
     *
     * @param string $productId     Google Play の商品 ID
     * @param string $purchaseToken 購入トークン
     * @return bool 常に true（API が例外をスローした場合は呼び出し元に伝播する）
     */
    public function acknowledge(string $productId, string $purchaseToken): bool
    {
        $this->publisher->purchases_products->acknowledge(
            $this->config->packageName,
            $productId,
            $purchaseToken,
            new AndroidPublisher\ProductPurchasesAcknowledgeRequest,
        );

        return true;
    }

    /**
     * Google Play は purchaseToken ベースのため refreshSubscriptionStatus はサポートしない。
     *
     * @param string $originalTransactionId 未使用
     * @param string $productId             未使用
     * @return VerificationResult 常に isValid: false
     */
    public function refreshSubscriptionStatus(string $originalTransactionId, string $productId): VerificationResult
    {
        return new VerificationResult(
            isValid: false,
            transactionId: $originalTransactionId,
            productId: $productId,
            rawResponse: [],
            errorMessage: 'Google Play does not support refreshSubscriptionStatus. Use verifySubscription with purchase token instead.',
        );
    }
}
