<?php

namespace Abante4wd\Iap\DTO;

/**
 * ストア API から取得したサブスクリプション情報を保持する読み取り専用 DTO。
 *
 * VerificationResult に付与され、SubscriptionRepository への upsert に使われる。
 * Apple・Google 双方のストアで共通の形式として使用する。
 */
readonly class SubscriptionInfo
{
    /**
     * @param string             $originalTransactionId 初回購入時のトランザクション ID（Apple の originalTransactionId に相当）
     * @param string             $currentTransactionId  最新の更新トランザクション ID
     * @param \DateTimeImmutable $startsAt              購読開始日時
     * @param \DateTimeImmutable $expiresAt             現在の有効期限
     * @param bool               $autoRenewing          自動更新が有効かどうか
     * @param string             $status                サブスクリプション状態（'active'・'expired'・'cancelled' 等）
     */
    public function __construct(
        public string $originalTransactionId,
        public string $currentTransactionId,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $expiresAt,
        public bool $autoRenewing,
        public string $status,
        public bool $isInBillingRetry = false,
        public ?\DateTimeImmutable $gracePeriodExpiresAt = null,
    ) {}
}
