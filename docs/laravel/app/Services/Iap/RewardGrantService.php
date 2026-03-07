<?php

declare(strict_types=1);

namespace App\Services\Iap;

use App\Models\Product;
use Fukazawa\Iap\Contracts\RewardGrantServiceInterface;
use Fukazawa\Iap\DTO\PurchaseData;

class RewardGrantService implements RewardGrantServiceInterface
{
    /**
     * 購入に応じた報酬を付与する。
     * アプリ固有のロジックに合わせて実装を変更してください。
     *
     * @return array<int, array{type: string, identifier: string|null, quantity: int}>
     */
    public function grant(PurchaseData $purchase): array
    {
        $product = Product::find($purchase->productId);

        if (! $product) {
            return [];
        }

        // TODO: アプリ固有の報酬付与ロジックを実装
        // 例: ユーザーにジェムを付与、プレミアムステータスを有効化、など
        //
        // $user = User::find($purchase->userId);
        // $user->addGems(100);
        //
        // return [
        //     ['type' => 'currency', 'identifier' => 'gem', 'quantity' => 100],
        // ];

        return [];
    }
}
