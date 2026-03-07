<?php

declare(strict_types=1);

require_once __DIR__.'/../vendor/autoload.php';

use App\InMemoryPurchaseRepository;
use App\InMemorySubscriptionRepository;
use App\SampleRewardGrantService;
use Fukazawa\Iap\DTO\ProductData;
use Fukazawa\Iap\DTO\SubscriptionInfo;
use Fukazawa\Iap\DTO\VerificationResult;
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Enums\PurchaseType;
use Fukazawa\Iap\Store\Config\AppleConfig;
use Fukazawa\Iap\Store\Config\GoogleConfig;

echo "=== fukazawa/iap パッケージ利用サンプル ===\n";
echo 'PHP '.PHP_VERSION."\n\n";

// -----------------------------------------------
// 1. Config DTO の作成
// -----------------------------------------------
echo "--- 1. Config DTO ---\n";

$googleConfig = new GoogleConfig(
    applicationName: 'My Example App',
    serviceAccountJsonPath: '/path/to/service-account.json',
    packageName: 'com.example.myapp',
);
echo "GoogleConfig: package={$googleConfig->packageName}\n";

$appleConfig = new AppleConfig(
    bundleId: 'com.example.myapp',
    issuerId: '00000000-0000-0000-0000-000000000000',
    keyId: 'ABCDE12345',
    privateKeyPath: '/path/to/AuthKey.p8',
    environment: 'sandbox',
);
echo "AppleConfig: bundleId={$appleConfig->bundleId}, env={$appleConfig->environment}\n\n";

// -----------------------------------------------
// 2. DTO の利用デモ
// -----------------------------------------------
echo "--- 2. DTO ---\n";

$product = new ProductData(
    id: 1,
    productId: 'gem_100',
    name: 'ジェム100個パック',
    type: PurchaseType::Consumable,
    googleProductId: 'com.example.myapp.gem_100',
    appleProductId: 'com.example.myapp.gem100',
);

echo "Product: {$product->productId} ({$product->name}, type: {$product->type->value})\n";
echo "  Google Store ID: {$product->storeProductId('google')}\n";
echo "  Apple Store ID:  {$product->storeProductId('apple')}\n\n";

// VerificationResult のデモ
$result = new VerificationResult(
    isValid: true,
    transactionId: 'GPA.1234-5678-9012-34567',
    productId: 'com.example.myapp.gem_100',
    rawResponse: ['purchaseState' => 0, 'consumptionState' => 0],
);
echo 'VerificationResult: valid='.($result->isValid ? 'true' : 'false')
    .", txId={$result->transactionId}, productId={$result->productId}\n";

// サブスクリプション付きの VerificationResult
$subInfo = new SubscriptionInfo(
    originalTransactionId: 'GPA.0001-0002-0003',
    currentTransactionId: 'GPA.0001-0002-0003..0',
    startsAt: new DateTimeImmutable('now'),
    expiresAt: new DateTimeImmutable('+30 days'),
    autoRenewing: true,
    status: 'active',
);

$resultWithSub = new VerificationResult(
    isValid: true,
    transactionId: $subInfo->currentTransactionId,
    productId: 'com.example.myapp.monthly_premium',
    rawResponse: ['subscriptionState' => 1],
    subscriptionInfo: $subInfo,
);

echo "SubscriptionResult: status={$resultWithSub->subscriptionInfo->status}, "
    ."expires={$resultWithSub->subscriptionInfo->expiresAt->format('Y-m-d H:i:s')}, "
    .'autoRenew='.($resultWithSub->subscriptionInfo->autoRenewing ? 'yes' : 'no')."\n\n";

// -----------------------------------------------
// 3. Enum の利用デモ
// -----------------------------------------------
echo "--- 3. Enums ---\n";

echo 'Platforms: ';
foreach (Platform::cases() as $p) {
    echo $p->value.' ';
}
echo "\n";

echo 'PurchaseTypes: ';
foreach (PurchaseType::cases() as $t) {
    echo $t->value.' ';
}
echo "\n\n";

// -----------------------------------------------
// 4. Repository / Service インターフェース実装デモ
// -----------------------------------------------
echo "--- 4. Repository & Service implementations ---\n";

$purchaseRepo = new InMemoryPurchaseRepository;
$subscriptionRepo = new InMemorySubscriptionRepository;
$rewardService = new SampleRewardGrantService;

// 商品検索
$found = $purchaseRepo->findProductByProductId('gem_100');
echo "Found product: {$found->productId} ({$found->name}, type: {$found->type->value})\n";

// 購入記録の作成
$purchase = $purchaseRepo->createOrUpdateVerified(
    userId: 42,
    productId: $found->id,
    platform: Platform::Google,
    txId: 'GPA.1234-5678-9012-34567',
    token: 'sample-purchase-token-abc123',
    receipt: null,
    response: ['purchaseState' => 0],
);
echo "Created purchase: id={$purchase->id}, status={$purchase->status->value}\n";

// Acknowledge
$purchaseRepo->markAcknowledged($purchase->id);

// 報酬付与
$rewards = $rewardService->grant($purchase);
$purchaseRepo->markRewardsGranted($purchase->id);

echo "\n";

// サブスクリプションの upsert
echo "--- Subscription upsert ---\n";
$subscriptionRepo->upsert(
    userId: 42,
    productId: 2,
    platform: Platform::Google,
    info: $subInfo,
);

// 重複チェック
$duplicate = $purchaseRepo->findVerifiedByPlatformAndTransactionId(
    Platform::Google,
    'GPA.1234-5678-9012-34567',
);
echo "\nDuplicate check: ".($duplicate ? "found (id={$duplicate->id})" : 'not found')."\n\n";

echo "=== サンプル完了 ===\n";
