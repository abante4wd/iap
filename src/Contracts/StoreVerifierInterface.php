<?php

namespace Abante4wd\Iap\Contracts;

use Abante4wd\Iap\DTO\VerificationResult;

/**
 * ストア購入検証を行うクラスのインターフェース。
 *
 * Apple App Store・Google Play など、プラットフォームごとの実装が提供する。
 */
interface StoreVerifierInterface
{
    /**
     * 単品・消耗品購入を検証する。
     *
     * @param string      $productId            商品 ID
     * @param string      $purchaseToken        クライアントから受け取った購入トークン
     *                                          （Apple: jwsRepresentation、Google: purchaseToken）
     * @param string|null $receiptData          Apple レシートデータ（旧 StoreKit 1 向け、省略可）
     * @param bool|null   $clientReportsPending クライアントが保留中と報告している場合は true（Ask to Buy 等）
     * @return VerificationResult 検証結果
     */
    public function verifyProduct(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult;

    /**
     * サブスクリプション購入を検証する。
     *
     * @param string      $productId            商品 ID
     * @param string      $purchaseToken        クライアントから受け取った購入トークン
     * @param string|null $receiptData          Apple レシートデータ（旧 StoreKit 1 向け、省略可）
     * @param bool|null   $clientReportsPending クライアントが保留中と報告している場合は true
     * @return VerificationResult 検証結果（有効な場合は subscriptionInfo が付与される）
     */
    public function verifySubscription(string $productId, string $purchaseToken, ?string $receiptData = null, ?bool $clientReportsPending = null): VerificationResult;

    /**
     * 購入を Acknowledge（消費確認）する。
     *
     * Google Play の消耗品は Acknowledge しないと 3 日後に自動返金される。
     * Apple は常に true を返す（Acknowledge 不要）。
     *
     * @param string $productId     商品 ID
     * @param string $purchaseToken 購入トークン
     * @return bool Acknowledge が成功した場合は true
     */
    public function acknowledge(string $productId, string $purchaseToken): bool;
}
