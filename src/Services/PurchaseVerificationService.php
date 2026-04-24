<?php

namespace Abante4wd\Iap\Services;

use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;
use Abante4wd\Iap\Contracts\RewardGrantServiceInterface;
use Abante4wd\Iap\Contracts\SubscriptionRepositoryInterface;
use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;
use Abante4wd\Iap\Enums\PurchaseType;
use Abante4wd\Iap\Exceptions\PurchaseAlreadyVerifiedException;
use Abante4wd\Iap\Exceptions\StoreVerificationFailedException;
use Abante4wd\Iap\Store\StoreVerifierFactory;

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
        $storeProductId = $product->storeProductId($platform);

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
                $result->transactionId ?: 'pending_'.hash('sha256', $purchaseToken),
                $purchaseToken,
                $receiptData,
                $result->rawResponse,
                $result->pendingReason ?? \Abante4wd\Iap\Enums\PendingReason::Unknown,
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
                $result->transactionId ?: 'failed_'.hash('sha256', $purchaseToken),
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
                $acknowledged = $verifier->acknowledge($product->storeProductId($platform), $purchaseToken);
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
