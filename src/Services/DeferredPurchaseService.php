<?php

namespace Abante4wd\Iap\Services;

use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;
use Abante4wd\Iap\Contracts\RewardGrantServiceInterface;
use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseType;
use Abante4wd\Iap\Store\StoreVerifierFactory;

/**
 * 保留中の購入（Ask to Buy・支払い保留等）を管理するサービス。
 *
 * サーバー通知受信時に completePending / cancelPending を呼び出すことで
 * 保留状態から完了・キャンセルへ遷移させる。
 * recheckPendingPurchases はバッチジョブから定期実行する用途を想定している。
 */
class DeferredPurchaseService
{
    /**
     * @param StoreVerifierFactory       $verifierFactory   ストア別ベリファイアのファクトリー
     * @param PurchaseRepositoryInterface $purchaseRepo      購入レコードリポジトリ
     * @param RewardGrantServiceInterface $rewardGrantService 報酬付与サービス
     */
    public function __construct(
        private StoreVerifierFactory $verifierFactory,
        private PurchaseRepositoryInterface $purchaseRepo,
        private RewardGrantServiceInterface $rewardGrantService,
    ) {}

    /**
     * 保留中の購入をストアに再検証し、承認済みなら完了させる。
     *
     * 対応する保留レコードが見つからない、またはストア検証が失敗した場合は null を返す。
     * 完了処理はトランザクション内で行い、Google 消耗品の Acknowledge と報酬付与も同時に実施する。
     *
     * @param Platform $platform      対象プラットフォーム
     * @param string   $purchaseToken 保留中の購入トークン
     * @return PurchaseData|null 完了した購入レコード。未完了・未発見の場合は null
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
     * 保留中の購入をキャンセルする。
     *
     * 対応する保留レコードが見つからない場合は何もしない。
     *
     * @param Platform    $platform      対象プラットフォーム
     * @param string      $purchaseToken 保留中の購入トークン
     * @param string|null $reason        キャンセル理由（省略可）
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
     * すべての保留中の購入を再検証する（バッチ処理用）。
     *
     * Google・Apple 両プラットフォームの保留レコードを順に処理し、
     * 完了・未完了・失敗の件数を返す。
     * 個々の処理が例外をスローしても他のレコードの処理は続行する。
     *
     * @return array{completed: int, still_pending: int, failed: int} 処理結果の集計
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
