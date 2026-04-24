<?php

namespace Abante4wd\Iap\Contracts;

use Abante4wd\Iap\DTO\SubscriptionData;
use Abante4wd\Iap\DTO\SubscriptionInfo;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\SubscriptionStatus;

/**
 * サブスクリプションレコードの永続化を担うリポジトリインターフェース。
 *
 * ホストアプリが Eloquent・Query Builder 等の実装を提供する。
 */
interface SubscriptionRepositoryInterface
{
    /**
     * サブスクリプション情報を登録または更新する。
     *
     * originalTransactionId をキーに upsert し、最新の有効期限・状態を反映する。
     *
     * @param int|string       $userId    ユーザー ID
     * @param int|string       $productId 商品 ID
     * @param Platform         $platform  プラットフォーム
     * @param SubscriptionInfo $info      ストアから取得したサブスクリプション情報
     */
    public function upsert(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        SubscriptionInfo $info,
    ): void;

    /**
     * 指定日時より前に有効期限が切れる、かつ指定ステータスのサブスクリプションを返す。
     *
     * SubscriptionCheckService のバッチ処理で期限切れ予定を一括取得するために使用する。
     *
     * @param \DateTimeImmutable   $before   この日時より前に期限が切れるものを対象とする
     * @param SubscriptionStatus[] $statuses 絞り込むステータスの配列
     * @return SubscriptionData[]
     */
    public function findExpiringSubscriptions(\DateTimeImmutable $before, array $statuses): array;

    /**
     * ストアから取得した最新情報でサブスクリプションレコードを更新する。
     *
     * @param int|string       $subscriptionId 内部サブスクリプション ID
     * @param SubscriptionInfo $info           ストアから取得した最新のサブスクリプション情報
     */
    public function updateFromStoreInfo(int|string $subscriptionId, SubscriptionInfo $info): void;
}
