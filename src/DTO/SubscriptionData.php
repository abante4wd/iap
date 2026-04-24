<?php

namespace Abante4wd\Iap\DTO;

use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\SubscriptionStatus;

/**
 * サブスクリプションレコードを表す読み取り専用 DTO。
 *
 * 有効期限・更新状態の管理に使用し、SubscriptionCheckService による
 * 期限切れチェックのバッチ処理でも参照される。
 */
readonly class SubscriptionData
{
    /**
     * @param int|string         $id                    内部サブスクリプション ID
     * @param int|string         $userId                サブスクリプションを保有するユーザーの ID
     * @param int|string         $productId             対応商品の内部 ID
     * @param Platform           $platform              購入プラットフォーム
     * @param string             $originalTransactionId 初回購入時のトランザクション ID
     * @param string             $currentTransactionId  最新の更新トランザクション ID
     * @param string             $storeProductId        ストア上の商品 ID
     * @param SubscriptionStatus $status                現在のサブスクリプションステータス
     * @param \DateTimeImmutable $expiresAt             有効期限
     */
    public function __construct(
        public int|string $id,
        public int|string $userId,
        public int|string $productId,
        public Platform $platform,
        public string $originalTransactionId,
        public string $currentTransactionId,
        public string $storeProductId,
        public SubscriptionStatus $status,
        public \DateTimeImmutable $expiresAt,
    ) {}
}
