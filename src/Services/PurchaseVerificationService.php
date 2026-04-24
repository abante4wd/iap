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

/**
 * アプリ内購入の検証フローを統括するサービス。
 *
 * ストア API による検証・購入レコードの永続化・報酬付与までを
 * 一連のトランザクションとして処理する。
 * 保留中・失敗・二重購入など各種ケースを適切に処理する。
 */
class PurchaseVerificationService
{
    /**
     * @param StoreVerifierFactory          $verifierFactory    ストア別ベリファイアのファクトリー
     * @param RewardGrantServiceInterface   $rewardGrantService 報酬付与サービス
     * @param PurchaseRepositoryInterface   $purchaseRepo       購入レコードリポジトリ
     * @param SubscriptionRepositoryInterface $subscriptionRepo サブスクリプションリポジトリ
     */
    public function __construct(
        private StoreVerifierFactory $verifierFactory,
        private RewardGrantServiceInterface $rewardGrantService,
        private PurchaseRepositoryInterface $purchaseRepo,
        private SubscriptionRepositoryInterface $subscriptionRepo,
    ) {}

    /**
     * 購入を検証し、成功時は購入レコードを作成して報酬を付与する。
     *
     * 保留中の場合は status='pending' で返し、例外はスローしない。
     * 検証失敗の場合は失敗レコードを作成した上で StoreVerificationFailedException をスローする。
     * 同一トランザクションが既に検証済みの場合は PurchaseAlreadyVerifiedException をスローする。
     *
     * @param int|string  $userId        ユーザー ID
     * @param Platform    $platform      購入プラットフォーム
     * @param string      $productId     商品識別子（プラットフォーム非依存）
     * @param string      $purchaseToken ストアからクライアントへ渡されたトークン
     * @param string|null $receiptData   Apple レシートデータ（旧 StoreKit 1 向け、省略可）
     * @return array{purchase: PurchaseData, rewards: array, status?: string} 検証結果
     *
     * @throws StoreVerificationFailedException 商品未発見またはストア検証失敗
     * @throws PurchaseAlreadyVerifiedException 同一トランザクションが既に検証済み
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
