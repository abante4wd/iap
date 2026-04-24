<?php

namespace Abante4wd\Iap\Services;

use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;
use Abante4wd\Iap\Contracts\RewardGrantServiceInterface;
use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseType;
use Abante4wd\Iap\Store\StoreVerifierFactory;

class DeferredPurchaseService
{
    public function __construct(
        private StoreVerifierFactory $verifierFactory,
        private PurchaseRepositoryInterface $purchaseRepo,
        private RewardGrantServiceInterface $rewardGrantService,
    ) {}

    /**
     * 保留中の購入をストアに再検証し、承認済みなら完了させる
     */
    public function completePending(Platform $platform, string $purchaseToken): ?PurchaseData
    {
        $pending = $this->purchaseRepo->findPendingByPlatformAndToken($platform, $purchaseToken);
        if (! $pending) {
            return null;
        }

        $product = $this->purchaseRepo->findProductByProductId((string) $pending->productId);
        if (! $product) {
            return null;
        }

        $verifier = $this->verifierFactory->make($platform);
        $storeProductId = $product->storeProductId($platform);

        $isSubscription = $product->type === PurchaseType::Subscription;
        $result = $isSubscription
            ? $verifier->verifySubscription($storeProductId, $purchaseToken)
            : $verifier->verifyProduct($storeProductId, $purchaseToken);

        if (! $result->isValid) {
            return null;
        }

        return $this->purchaseRepo->transaction(function () use ($pending, $result, $platform, $product, $purchaseToken, $verifier) {
            $purchase = $this->purchaseRepo->completePending(
                $pending->id,
                $result->transactionId,
                $result->rawResponse,
            );

            if ($platform === Platform::Google && $product->type === PurchaseType::Consumable) {
                $acknowledged = $verifier->acknowledge($product->storeProductId($platform), $purchaseToken);
                if ($acknowledged) {
                    $this->purchaseRepo->markAcknowledged($purchase->id);
                }
            }

            $rewards = $this->rewardGrantService->grant($purchase);
            $this->purchaseRepo->markRewardsGranted($purchase->id);

            return $purchase;
        });
    }

    /**
     * 保留中の購入をキャンセルする
     */
    public function cancelPending(Platform $platform, string $purchaseToken, ?string $reason = null): void
    {
        $pending = $this->purchaseRepo->findPendingByPlatformAndToken($platform, $purchaseToken);
        if (! $pending) {
            return;
        }

        $this->purchaseRepo->cancelPending($pending->id, $reason);
    }

    /**
     * すべての保留中の購入を再検証する（バッチ処理用）
     *
     * @return array{completed: int, still_pending: int, failed: int}
     */
    public function recheckPendingPurchases(): array
    {
        $stats = ['completed' => 0, 'still_pending' => 0, 'failed' => 0];

        foreach ([Platform::Google, Platform::Apple] as $platform) {
            $pendingList = $this->purchaseRepo->findAllPendingByPlatform($platform);

            foreach ($pendingList as $pending) {
                try {
                    $result = $this->completePending($platform, $pending->purchaseToken);
                    if ($result) {
                        $stats['completed']++;
                    } else {
                        $stats['still_pending']++;
                    }
                } catch (\Exception $e) {
                    $stats['failed']++;
                }
            }
        }

        return $stats;
    }
}
