# fukazawa/iap

フレームワーク非依存のアプリ内課金（IAP）検証パッケージ。

Google Play / Apple App Store のサーバーサイドレシート検証、サブスクリプション状態管理のコアロジックを提供する。
Laravel 等の特定フレームワークに依存せず、Repository インターフェース + DTO を介してホストアプリと接続する。

## インストール

### プライベートリポジトリから

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:fukazawa/iap.git"
        }
    ],
    "require": {
        "fukazawa/iap": "^1.0"
    }
}
```

```bash
composer update
```

### ローカル開発（モノレポ構成）

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "packages/iap"
        }
    ],
    "require": {
        "fukazawa/iap": "*"
    }
}
```

### ZIP ファイルから導入する場合

Git アクセス権がない場合は、ZIP 配布からインストールできます。

1. ZIP を展開し、プロジェクト内の `packages/iap/` に配置:

```
your-project/
├── packages/
│   └── iap/          ← ZIP の中身をここに展開
│       ├── composer.json
│       └── src/
├── composer.json
└── ...
```

2. `composer.json` に `path` リポジトリを追加:

```json
{
    "repositories": [
        {
            "type": "path",
            "url": "./packages/iap"
        }
    ]
}
```

3. パッケージをインストール:

```bash
composer require fukazawa/iap:*
```

## パッケージ構造

```
src/
├── Contracts/          # ホストアプリが実装するインターフェース
├── DTO/                # データ転送オブジェクト
├── Enums/              # 列挙型
├── Exceptions/         # 例外
├── Services/           # ビジネスロジック
└── Store/              # ストア検証（Google / Apple）
    └── Config/         # 設定 DTO
```

## 基本的な使い方

### 1. Config DTO を作成する

```php
use Fukazawa\Iap\Store\Config\GoogleConfig;
use Fukazawa\Iap\Store\Config\AppleConfig;

$googleConfig = new GoogleConfig(
    applicationName: 'My App',
    serviceAccountJsonPath: '/path/to/service-account.json',
    packageName: 'com.example.myapp',
);

$appleConfig = new AppleConfig(
    bundleId: 'com.example.myapp',
    issuerId: 'xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx',
    keyId: 'XXXXXXXXXX',
    privateKeyPath: '/path/to/AuthKey.p8',
    environment: 'sandbox', // 'sandbox' or 'production'
);
```

### 2. Verifier を直接使う（単体検証）

```php
use Fukazawa\Iap\Store\GooglePlayVerifier;
use Fukazawa\Iap\Store\AppleStoreVerifier;

// Google Play
$verifier = new GooglePlayVerifier($googleConfig);
$result = $verifier->verifyProduct('com.example.gem_100', $purchaseToken);

if ($result->isValid) {
    echo "Transaction ID: {$result->transactionId}";
} else {
    echo "Error: {$result->errorMessage}";
}

// Apple App Store
$verifier = new AppleStoreVerifier($appleConfig);
$result = $verifier->verifySubscription('com.example.monthly', $transactionId);

if ($result->isValid && $result->subscriptionInfo) {
    echo "Expires: " . $result->subscriptionInfo->expiresAt->format('Y-m-d H:i:s');
}
```

### 3. StoreVerifierFactory でプラットフォームを切り替える

```php
use Fukazawa\Iap\Enums\Platform;
use Fukazawa\Iap\Store\StoreVerifierFactory;

$factory = new StoreVerifierFactory(
    new GooglePlayVerifier($googleConfig),
    new AppleStoreVerifier($appleConfig),
);

$verifier = $factory->make(Platform::Google);
$result = $verifier->verifyProduct($storeProductId, $purchaseToken);
```

### 4. PurchaseVerificationService（フルフロー）

検証 → 購入記録 → acknowledge → サブスク upsert → 報酬付与を一括で行う。
事前に 3 つの Repository / Service インターフェースをホストアプリ側で実装する必要がある。

```php
use Fukazawa\Iap\Services\PurchaseVerificationService;
use Fukazawa\Iap\Enums\Platform;

$service = new PurchaseVerificationService(
    verifierFactory: $factory,
    rewardGrantService: $myRewardGrantService,   // RewardGrantServiceInterface 実装
    purchaseRepo: $myPurchaseRepository,          // PurchaseRepositoryInterface 実装
    subscriptionRepo: $mySubscriptionRepository,  // SubscriptionRepositoryInterface 実装
);

$result = $service->verify(
    userId: 42,
    platform: Platform::Google,
    productId: 'gem_100',
    purchaseToken: '...',
    receiptData: null,
);

// $result['purchase'] => PurchaseData
// $result['rewards']  => array<array{type, identifier, quantity}>
```

### 5. SubscriptionCheckService（期限切れ検査）

```php
use Fukazawa\Iap\Services\SubscriptionCheckService;

$checker = new SubscriptionCheckService(
    verifierFactory: $factory,
    subscriptionRepo: $mySubscriptionRepository,
);

$result = $checker->checkExpiring(
    onProgress: fn (string $msg) => echo $msg . PHP_EOL,
);

// $result => ['checked' => 10, 'updated' => 8, 'errors' => 0]
```

## ホストアプリが実装するインターフェース

### PurchaseRepositoryInterface

購入記録・商品マスタへのアクセスを抽象化する。

```php
use Fukazawa\Iap\Contracts\PurchaseRepositoryInterface;

class MyPurchaseRepository implements PurchaseRepositoryInterface
{
    public function findProductByProductId(string $productId): ?ProductData { ... }
    public function findVerifiedByPlatformAndTransactionId(Platform $platform, string $txId): ?PurchaseData { ... }
    public function createFailed(...): PurchaseData { ... }
    public function createOrUpdateVerified(...): PurchaseData { ... }
    public function markAcknowledged(int|string $purchaseId): void { ... }
    public function markRewardsGranted(int|string $purchaseId): void { ... }
    public function transaction(callable $callback): mixed { ... }
}
```

### SubscriptionRepositoryInterface

サブスクリプション状態の永続化を抽象化する。

```php
use Fukazawa\Iap\Contracts\SubscriptionRepositoryInterface;

class MySubscriptionRepository implements SubscriptionRepositoryInterface
{
    public function upsert(int|string $userId, int|string $productId, Platform $platform, SubscriptionInfo $info): void { ... }
    public function findExpiringSubscriptions(\DateTimeImmutable $before, array $statuses): array { ... }
    public function updateFromStoreInfo(int|string $subscriptionId, SubscriptionInfo $info): void { ... }
}
```

### RewardGrantServiceInterface

購入成功後の報酬付与ロジックを抽象化する。

```php
use Fukazawa\Iap\Contracts\RewardGrantServiceInterface;

class MyRewardGrantService implements RewardGrantServiceInterface
{
    /** @return array<int, array{type: string, identifier: string|null, quantity: int}> */
    public function grant(PurchaseData $purchase): array { ... }
}
```

## DTO 一覧

| クラス | 用途 |
|---|---|
| `ProductData` | 商品マスタ情報。`storeProductId($platform)` でストア別商品 ID を取得 |
| `PurchaseData` | 購入トランザクション記録 |
| `SubscriptionData` | サブスクリプション状態（期限切れ検査用） |
| `SubscriptionInfo` | ストア API から取得したサブスクリプション情報 |
| `VerificationResult` | ストア API 検証結果 |

## Enum 一覧

| Enum | 値 |
|---|---|
| `Platform` | `google`, `apple` |
| `PurchaseStatus` | `pending`, `verified`, `failed`, `refunded` |
| `PurchaseType` | `consumable`, `non_consumable`, `subscription` |
| `SubscriptionStatus` | `active`, `expired`, `cancelled`, `grace_period`, `paused` |

## 例外一覧

| 例外 | 発生条件 |
|---|---|
| `StoreVerificationFailedException` | ストア API がレシートを無効と判定 |
| `PurchaseAlreadyVerifiedException` | 同一トランザクション ID が検証済み |
| `RewardGrantFailedException` | 報酬付与に失敗（報酬定義なし等） |

## Laravel での統合例

`AppServiceProvider` でバインディングを設定する。

```php
// AppServiceProvider::register()

$this->app->singleton(GoogleConfig::class, fn () => new GoogleConfig(
    applicationName: config('iap.google.application_name'),
    serviceAccountJsonPath: config('iap.google.service_account_json'),
    packageName: config('iap.google.package_name'),
));

$this->app->singleton(AppleConfig::class, fn () => new AppleConfig(
    bundleId: config('iap.apple.bundle_id'),
    issuerId: config('iap.apple.issuer_id'),
    keyId: config('iap.apple.key_id'),
    privateKeyPath: config('iap.apple.private_key_path'),
    environment: config('iap.apple.environment'),
));

$this->app->singleton(GooglePlayVerifier::class);
$this->app->singleton(AppleStoreVerifier::class);

$this->app->bind(PurchaseRepositoryInterface::class, EloquentPurchaseRepository::class);
$this->app->bind(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);
$this->app->bind(RewardGrantServiceInterface::class, RewardGrantService::class);
```

Controller からは DI で `PurchaseVerificationService` を受け取るだけでよい。

```php
class PurchaseController extends Controller
{
    public function __construct(
        private PurchaseVerificationService $verificationService,
    ) {}

    public function verify(Request $request): JsonResponse
    {
        $result = $this->verificationService->verify(
            userId: $request->user()->id,
            platform: Platform::from($request->input('platform')),
            productId: $request->input('product_id'),
            purchaseToken: $request->input('purchase_token'),
        );

        return response()->json(['purchase_id' => $result['purchase']->id]);
    }
}
```

## 依存関係

- `guzzlehttp/guzzle` ^7.0 — Apple App Store API の HTTP 通信
- `firebase/php-jwt` ^7.0 — Apple JWT 生成
- `google/apiclient` ^2.18 — Google Play Android Publisher API

**illuminate/* への依存は一切ない。**
