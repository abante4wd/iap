<?php

namespace Abante4wd\Iap\DTO;

use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;

/**
 * 購入レコードを表す読み取り専用 DTO。
 *
 * 検証済み・失敗・保留中のいずれの状態も同一クラスで表現する。
 * 各タイムスタンプは該当アクションが未実施の場合 null となる。
 */
readonly class PurchaseData
{
    /**
     * @param int|string              $id                 内部購入レコード ID
     * @param int|string              $userId             購入を行ったユーザーの ID
     * @param int|string              $productId          購入商品の内部 ID
     * @param Platform                $platform           購入プラットフォーム
     * @param string                  $storeTransactionId ストアが発行したトランザクション ID
     * @param string                  $purchaseToken      ストアからクライアントへ渡されたトークン
     * @param PurchaseStatus          $status             現在の購入ステータス
     * @param string|null             $receiptPayload     Apple レシートデータ等（省略可）
     * @param array|null              $storeResponse      ストア API の生レスポンス
     * @param \DateTimeImmutable|null $verifiedAt         ストア検証が完了した日時
     * @param \DateTimeImmutable|null $acknowledgedAt     Acknowledge 完了日時（Google 消耗品等）
     * @param \DateTimeImmutable|null $rewardsGrantedAt   報酬付与が完了した日時
     * @param PendingReason|null      $pendingReason      保留理由（保留中の場合のみ設定）
     * @param \DateTimeImmutable|null $deferredAt         保留状態に移行した日時
     * @param \DateTimeImmutable|null $completedAt        保留→完了に遷移した日時
     */
    public function __construct(
        public int|string $id,
        public int|string $userId,
        public int|string $productId,
        public Platform $platform,
        public string $storeTransactionId,
        public string $purchaseToken,
        public PurchaseStatus $status,
        public ?string $receiptPayload = null,
        public ?array $storeResponse = null,
        public ?\DateTimeImmutable $verifiedAt = null,
        public ?\DateTimeImmutable $acknowledgedAt = null,
        public ?\DateTimeImmutable $rewardsGrantedAt = null,
        public ?PendingReason $pendingReason = null,
        public ?\DateTimeImmutable $deferredAt = null,
        public ?\DateTimeImmutable $completedAt = null,
    ) {}
}
