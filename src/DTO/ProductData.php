<?php

namespace Abante4wd\Iap\DTO;

use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseType;

/**
 * プロダクト（商品）情報を表す読み取り専用 DTO。
 *
 * ストアごとに異なる商品 ID（googleProductId / appleProductId）を持ち、
 * プラットフォームを指定してストア用の商品 ID を取得できる。
 */
readonly class ProductData
{
    /**
     * @param int|string   $id              内部プロダクト ID
     * @param string       $productId       プラットフォーム非依存の商品識別子
     * @param string       $name            商品表示名
     * @param PurchaseType $type            購入種別（消耗品・非消耗品・サブスクリプション）
     * @param string|null  $googleProductId Google Play 用の商品 ID（未設定時は $productId を使用）
     * @param string|null  $appleProductId  App Store 用の商品 ID（未設定時は $productId を使用）
     */
    public function __construct(
        public int|string $id,
        public string $productId,
        public string $name,
        public PurchaseType $type,
        public ?string $googleProductId = null,
        public ?string $appleProductId = null,
    ) {}

    /**
     * 指定プラットフォーム向けのストア商品 ID を返す。
     *
     * プラットフォーム固有の商品 ID が設定されていない場合は共通の $productId を返す。
     *
     * @param Platform $platform 対象プラットフォーム
     * @return string ストア商品 ID
     */
    public function storeProductId(Platform $platform): string
    {
        return match ($platform) {
            Platform::Google => $this->googleProductId ?? $this->productId,
            Platform::Apple => $this->appleProductId ?? $this->productId,
        };
    }
}
