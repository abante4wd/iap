<?php

declare(strict_types=1);

namespace App;

use Abante4wd\Iap\Contracts\RewardGrantServiceInterface;
use Abante4wd\Iap\DTO\PurchaseData;

class SampleRewardGrantService implements RewardGrantServiceInterface
{
    /** @return array<int, array{type: string, identifier: string|null, quantity: int}> */
    public function grant(PurchaseData $purchase): array
    {
        // サンプル: productId に応じた報酬を返す
        $rewards = [
            'gem_100' => [
                ['type' => 'currency', 'identifier' => 'gem', 'quantity' => 100],
            ],
            'monthly_premium' => [
                ['type' => 'subscription', 'identifier' => 'premium', 'quantity' => 1],
            ],
        ];

        $product = $this->resolveProductId($purchase->productId);
        $granted = $rewards[$product] ?? [];

        foreach ($granted as $reward) {
            echo "  [RewardGrant] Granted {$reward['quantity']}x {$reward['identifier']} ({$reward['type']})\n";
        }

        return $granted;
    }

    private function resolveProductId(int|string $productId): string
    {
        // 数値IDの場合はサンプル用にマッピング
        $map = [1 => 'gem_100', 2 => 'monthly_premium'];

        if (is_int($productId) || is_numeric($productId)) {
            return $map[(int) $productId] ?? (string) $productId;
        }

        return (string) $productId;
    }
}
