<?php

namespace Abante4wd\Iap\DTO;

use Abante4wd\Iap\Enums\PendingReason;

/**
 * ストア検証の結果を表す DTO。
 *
 * 成功・失敗・保留中のすべての結果を同一クラスで表現する。
 * サブスクリプション検証の場合は subscriptionInfo が付与される。
 */
class VerificationResult
{
    /**
     * @param bool                  $isValid          検証が成功したかどうか
     * @param string                $transactionId    ストアのトランザクション ID
     * @param string                $productId        商品 ID
     * @param array                 $rawResponse      ストア API の生レスポンス
     * @param string|null           $errorMessage     失敗時のエラーメッセージ（成功時は null）
     * @param SubscriptionInfo|null $subscriptionInfo サブスクリプション検証時のサブスクリプション情報
     * @param bool                  $isPending        購入が保留中かどうか（Ask to Buy・支払い保留等）
     * @param PendingReason|null    $pendingReason    保留理由（isPending が true の場合に設定）
     */
    public function __construct(
        public readonly bool $isValid,
        public readonly string $transactionId,
        public readonly string $productId,
        public readonly array $rawResponse,
        public readonly ?string $errorMessage = null,
        public readonly ?SubscriptionInfo $subscriptionInfo = null,
        public readonly bool $isPending = false,
        public readonly ?PendingReason $pendingReason = null,
    ) {}
}
