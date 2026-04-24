<?php

namespace Abante4wd\Iap\Store;

use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\DTO\VerificationResult;
use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Store\Config\GoogleConfig;
use Google\Client as GoogleClient;
use Google\Service\AndroidPublisher;

class GooglePlayVerifier implements StoreVerifierInterface
{
    private AndroidPublisher $publisher;

    public function __construct(
        private GoogleConfig $config,
    ) {
        $client = new GoogleClient;
        $client->setApplicationName($this->config->applicationName);
        $client->setAuthConfig($this->config->serviceAccountJsonPath);
        $client->addScope(AndroidPublisher::ANDROIDPUBLISHER);

        $this->publisher = new AndroidPublisher($client);
    }

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

            $latestOrderId = $response->getLatestOrderId() ?? '';

            $subscriptionInfo = new SubscriptionInfo(
                originalTransactionId: $latestOrderId,
                currentTransactionId: $latestOrderId,
                startsAt: new \DateTimeImmutable($response->getStartTime()),
                expiresAt: $expiryTime ? new \DateTimeImmutable($expiryTime) : new \DateTimeImmutable,
                autoRenewing: $autoRenewing,
                status: $status,
            );

            return new VerificationResult(
                isValid: $subscriptionState !== 'SUBSCRIPTION_STATE_EXPIRED',
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
}
