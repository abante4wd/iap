<?php

namespace Fukazawa\Iap\Services;

use Fukazawa\Iap\Contracts\PurchaseRepositoryInterface;
use Fukazawa\Iap\Contracts\RewardGrantServiceInterface;
use Fukazawa\Iap\Contracts\SubscriptionRepositoryInterface;
use Fukazawa\Iap\DTO\PurchaseData;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\PurchaseStatus;
use Fukazawa\Iap\Enums\PurchaseType;
use Fukazawa\Iap\Exceptions\PurchaseAlreadyVerifiedException;
use Fukazawa\Iap\Exceptions\StoreVerificationFailedException;
use Fukazawa\Iap\Store\StoreVerifierFactory;

class PurchaseVerificationService
{
    public function __construct(
        private StoreVerifierFactory $verifierFactory,
        private RewardGrantServiceInterface $rewardGrantService,
        private PurchaseRepositoryInterface $purchaseRepo,
        private SubscriptionRepositoryInterface $subscriptionRepo,
    ) {}

    /**
     * @return array{purchase: PurchaseData, rewards: array, status?: string}
     *
     * @throws StoreVerificationFailedException
     * @throws PurchaseAlreadyVerifiedException
     */
    public function verify(
        int|string $userId,
        Platform $platform,
        string $productId,
        string $purchaseToken,
        ?string $receiptData = null,
    ): array {
        $product = $this->purchaseRepo->findProductByProductId($productId);
        if (! $product) {
            throw new StoreVerificationFailedException("Product not found: {$productId}");
        }

        $verifier = $this->verifierFactory->make($platform);
        $storeProductId = $product->storeProductId($platform->value);

        $isSubscription = $product->type === PurchaseType::Subscription;

        $result = $isSubscription
            ? $verifier->verifySubscription($storeProductId, $purchaseToken, $receiptData)
            : $verifier->verifyProduct($storeProductId, $purchaseToken, $receiptData);

        // 保留中の購入（Ask to Buy、支払い保留など）
        if ($result->isPending) {
            $purchase = $this->purchaseRepo->createOrUpdatePending(
                $userId,
                $product->id,
                $platform,
                $result->transactionId ?: 'pending_'.uniqid(),
                $purchaseToken,
                $receiptData,
                $result->rawResponse,
                $result->pendingReason ?? \Fukazawa\Iap\Enums\PendingReason::Unknown,
            );

            return [
                'purchase' => $purchase,
                'rewards' => [],
                'status' => 'pending',
            ];
        }

        if (! $result->isValid) {
            $purchase = $this->purchaseRepo->createFailed(
                $userId,
                $product->id,
                $platform,
                $result->transactionId ?: 'unknown_'.uniqid(),
                $purchaseToken,
                $receiptData,
                $result->rawResponse,
            );

            throw new StoreVerificationFailedException($result->errorMessage ?? 'Store verification failed');
        }

        return $this->purchaseRepo->transaction(function () use (
            $userId, $product, $platform, $purchaseToken, $receiptData, $result, $isSubscription, $verifier,
        ) {
            $existing = $this->purchaseRepo->findVerifiedByPlatformAndTransactionId($platform, $result->transactionId);

            if ($existing && $existing->status === PurchaseStatus::Verified) {
                throw new PurchaseAlreadyVerifiedException('This purchase has already been verified');
            }

            $purchase = $this->purchaseRepo->createOrUpdateVerified(
                $userId,
                $product->id,
                $platform,
                $result->transactionId,
                $purchaseToken,
                $receiptData,
                $result->rawResponse,
            );

            if ($platform === Platform::Google && $product->type === PurchaseType::Consumable) {
                $acknowledged = $verifier->acknowledge($product->storeProductId($platform->value), $purchaseToken);
                if ($acknowledged) {
                    $this->purchaseRepo->markAcknowledged($purchase->id);
                }
            }

            if ($isSubscription && $result->subscriptionInfo) {
                $this->subscriptionRepo->upsert(
                    $userId,
                    $product->id,
                    $platform,
                    $result->subscriptionInfo,
                );
            }

            $rewards = $this->rewardGrantService->grant($purchase);
            $this->purchaseRepo->markRewardsGranted($purchase->id);

            return [
                'purchase' => $purchase,
                'rewards' => $rewards,
            ];
        });
    }
}
