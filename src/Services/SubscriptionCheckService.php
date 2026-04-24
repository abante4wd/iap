<?php

namespace Abante4wd\Iap\Services;

use Abante4wd\Iap\Contracts\SubscriptionRepositoryInterface;
use Abante4wd\Iap\Enums\SubscriptionStatus;
use Abante4wd\Iap\Store\StoreVerifierFactory;

/**
 * 有効期限が近いサブスクリプションをストアに再問い合わせして状態を更新するサービス。
 *
 * バッチジョブから定期実行することで、ストア側の更新・解約を DB に反映する。
 * onProgress コールバックを渡すことで進捗をログ出力等に連携できる。
 */
class SubscriptionCheckService
{
    /**
     * @param StoreVerifierFactory            $verifierFactory  ストア別ベリファイアのファクトリー
     * @param SubscriptionRepositoryInterface $subscriptionRepo サブスクリプションリポジトリ
     */
    public function __construct(
        private StoreVerifierFactory $verifierFactory,
        private SubscriptionRepositoryInterface $subscriptionRepo,
    ) {}

    /**
     * 翌日までに有効期限が切れる Active / GracePeriod のサブスクリプションを一括チェックする。
     *
     * 各サブスクリプションをストアに再問い合わせし、取得できた情報で DB を更新する。
     * 個々の処理が例外をスローしても他のレコードの処理は続行する。
     *
     * @param callable|null $onProgress 進捗メッセージを受け取るコールバック（fn(string $message): void）
     * @return array{checked: int, updated: int, errors: int} 処理結果の集計
     */
    public function checkExpiring(?callable $onProgress = null): array
    {
        $before = new \DateTimeImmutable('+1 day');
        $statuses = [SubscriptionStatus::Active, SubscriptionStatus::GracePeriod];

        $subscriptions = $this->subscriptionRepo->findExpiringSubscriptions($before, $statuses);

        $onProgress && $onProgress('Checking '.count($subscriptions).' subscriptions...');

        $updated = 0;
        $errors = 0;

        foreach ($subscriptions as $subscription) {
            try {
                $verifier = $this->verifierFactory->make($subscription->platform);

                $result = $verifier->refreshSubscriptionStatus(
                    $subscription->originalTransactionId,
                    $subscription->storeProductId,
                );

                if ($result->subscriptionInfo) {
                    $this->subscriptionRepo->updateFromStoreInfo(
                        $subscription->id,
                        $result->subscriptionInfo,
                    );
                    $updated++;
                }
            } catch (\Exception $e) {
                $errors++;
                $onProgress && $onProgress("Failed to check subscription {$subscription->id}: {$e->getMessage()}");
            }
        }

        $onProgress && $onProgress("Updated {$updated} subscriptions.");

        return [
            'checked' => count($subscriptions),
            'updated' => $updated,
            'errors' => $errors,
        ];
    }
}
