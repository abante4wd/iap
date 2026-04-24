<?php

namespace Abante4wd\Iap\Contracts;

use Abante4wd\Iap\DTO\PurchaseData;

/**
 * 購入完了時に報酬を付与するサービスのインターフェース。
 *
 * ホストアプリがゲームコイン・アイテム付与などのドメイン固有ロジックを実装する。
 */
interface RewardGrantServiceInterface
{
    /**
     * 購入情報に基づいて報酬を付与し、付与内容の一覧を返す。
     *
     * @param PurchaseData $purchase 完了した購入レコード
     * @return array<int, array{type: string, identifier: string|null, quantity: int}> 付与した報酬の一覧
     */
    public function grant(PurchaseData $purchase): array;
}
