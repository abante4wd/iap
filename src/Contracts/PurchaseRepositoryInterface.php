<?php

namespace Abante4wd\Iap\Contracts;

use Abante4wd\Iap\DTO\ProductData;
use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\PendingReason;
use Abante4wd\Iap\Enums\Platform;

/**
 * 購入レコードの永続化を担うリポジトリインターフェース。
 *
 * ホストアプリが Eloquent・Query Builder 等の実装を提供する。
 */
interface PurchaseRepositoryInterface
{
    /**
     * 商品識別子からプロダクト情報を取得する。
     *
     * @param string $productId プラットフォーム非依存の商品識別子
     * @return ProductData|null 見つからない場合は null
     */
    public function findProductByProductId(string $productId): ?ProductData;

    /**
     * プラットフォームとトランザクション ID で検証済み購入レコードを検索する。
     *
     * @param Platform $platform 対象プラットフォーム
     * @param string   $txId     ストアのトランザクション ID
     * @return PurchaseData|null 見つからない場合は null
     */
    public function findVerifiedByPlatformAndTransactionId(Platform $platform, string $txId): ?PurchaseData;

    /**
     * 検証失敗レコードを作成する。
     *
     * @param int|string  $userId    ユーザー ID
     * @param int|string  $productId 商品 ID
     * @param Platform    $platform  プラットフォーム
     * @param string      $txId      トランザクション ID
     * @param string      $token     購入トークン
     * @param string|null $receipt   Apple レシートデータ等
     * @param array       $response  ストア API の生レスポンス
     * @return PurchaseData 作成された購入レコード
     */
    public function createFailed(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
    ): PurchaseData;

    /**
     * 検証済み購入レコードを作成または更新する。
     *
     * 同一トランザクション ID が既に存在する場合は最新の情報で上書きする。
     *
     * @param int|string  $userId    ユーザー ID
     * @param int|string  $productId 商品 ID
     * @param Platform    $platform  プラットフォーム
     * @param string      $txId      トランザクション ID
     * @param string      $token     購入トークン
     * @param string|null $receipt   Apple レシートデータ等
     * @param array       $response  ストア API の生レスポンス
     * @return PurchaseData 作成または更新された購入レコード
     */
    public function createOrUpdateVerified(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
    ): PurchaseData;

    /**
     * 購入レコードを Acknowledge 済みにマークする。
     *
     * @param int|string $purchaseId 購入レコード ID
     */
    public function markAcknowledged(int|string $purchaseId): void;

    /**
     * 購入レコードの報酬付与を完了済みにマークする。
     *
     * @param int|string $purchaseId 購入レコード ID
     */
    public function markRewardsGranted(int|string $purchaseId): void;

    /**
     * 保留中の購入レコードを作成または更新する。
     *
     * Ask to Buy・支払い保留など、即座に完了しない購入に使用する。
     *
     * @param int|string    $userId    ユーザー ID
     * @param int|string    $productId 商品 ID
     * @param Platform      $platform  プラットフォーム
     * @param string        $txId      トランザクション ID（保留中は仮 ID の場合がある）
     * @param string        $token     購入トークン
     * @param string|null   $receipt   Apple レシートデータ等
     * @param array         $response  ストア API の生レスポンス
     * @param PendingReason $reason    保留理由
     * @return PurchaseData 作成または更新された保留購入レコード
     */
    public function createOrUpdatePending(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
        PendingReason $reason,
    ): PurchaseData;

    /**
     * プラットフォームと購入トークンで保留中のレコードを検索する。
     *
     * @param Platform $platform 対象プラットフォーム
     * @param string   $token    購入トークン
     * @return PurchaseData|null 見つからない場合は null
     */
    public function findPendingByPlatformAndToken(Platform $platform, string $token): ?PurchaseData;

    /**
     * 指定プラットフォームのすべての保留中購入レコードを返す。
     *
     * @param Platform $platform 対象プラットフォーム
     * @return PurchaseData[]
     */
    public function findAllPendingByPlatform(Platform $platform): array;

    /**
     * 保留中の購入を完了済みに遷移させる。
     *
     * @param int|string $purchaseId 購入レコード ID
     * @param string     $txId       完了後のトランザクション ID
     * @param array      $response   ストア API の生レスポンス
     * @return PurchaseData 更新後の購入レコード
     */
    public function completePending(int|string $purchaseId, string $txId, array $response): PurchaseData;

    /**
     * 保留中の購入をキャンセルする。
     *
     * @param int|string  $purchaseId 購入レコード ID
     * @param string|null $reason     キャンセル理由（省略可）
     */
    public function cancelPending(int|string $purchaseId, ?string $reason = null): void;

    /**
     * トランザクションスコープでコールバックを実行する。
     *
     * コールバック内で例外が発生した場合はロールバックする。
     *
     * @param callable $callback トランザクション内で実行する処理
     * @return mixed コールバックの戻り値
     */
    public function transaction(callable $callback): mixed;
}
