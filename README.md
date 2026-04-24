# abante4wd/iap

フレームワーク非依存のアプリ内課金（IAP）検証パッケージ。

Google Play / Apple App Store のサーバーサイドレシート検証、サブスクリプション状態管理のコアロジックを提供する。
Laravel 等の特定フレームワークに依存せず、Repository インターフェース + DTO を介してホストアプリと接続する。

## インストール

### GitHub リリースから（推奨）

`composer.json` に以下を追加してインストール:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "https://github.com/abante4wd/iap"
        }
    ],
    "require": {
        "abante4wd/iap": "^1.0"
    }
}
```

```bash
composer install
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
        "abante4wd/iap": "*"
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
composer require abante4wd/iap:*
```

## パッケージ構造

```
src/
├── Contracts/          # ホストアプリが実装するインターフェース
├── DTO/                # データ転送オブジェクト
├── Enums/              # 列挙型
├── Exceptions/         # 例外
├── Notifications/      # サーバー通知ハンドラー（Google / Apple）
├── Services/           # ビジネスロジック
└── Store/              # ストア検証（Google / Apple）
    └── Config/         # 設定 DTO
```

## 基本的な使い方

### 1. Config DTO を作成する

```php
use Abante4wd\Iap\Store\Config\GoogleConfig;
use Abante4wd\Iap\Store\Config\AppleConfig;

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
use Abante4wd\Iap\Store\GooglePlayVerifier;
use Abante4wd\Iap\Store\AppleStoreVerifier;

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
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Store\StoreVerifierFactory;

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
use Abante4wd\Iap\Services\PurchaseVerificationService;
use Abante4wd\Iap\Enums\Platform;

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
use Abante4wd\Iap\Services\SubscriptionCheckService;

$checker = new SubscriptionCheckService(
    verifierFactory: $factory,
    subscriptionRepo: $mySubscriptionRepository,
);

$result = $checker->checkExpiring(
    onProgress: fn (string $msg) => echo $msg . PHP_EOL,
);

// $result => ['checked' => 10, 'updated' => 8, 'errors' => 0]
```

### 6. DeferredPurchaseService（保留購入の処理）

Apple の Ask to Buy や Google Play の支払い保留など、保留中の購入を処理する。

```php
use Abante4wd\Iap\Services\DeferredPurchaseService;
use Abante4wd\Iap\Enums\Platform;

$deferredService = new DeferredPurchaseService(
    verifierFactory: $factory,
    purchaseRepo: $myPurchaseRepository,
    rewardGrantService: $myRewardGrantService,
);

// 保留中の購入をストアに再検証し、承認済みなら完了させる
$purchase = $deferredService->completePending(Platform::Google, $purchaseToken);
// $purchase => PurchaseData|null（null の場合は未承認または該当なし）

// 保留中の購入をキャンセルする
$deferredService->cancelPending(Platform::Google, $purchaseToken, '理由');
```

### 7. サーバー通知ハンドラー

ストアからのサーバー通知（Webhook）を処理するハンドラー。
`ServerNotificationHandlerInterface` を実装し、`handle()` メソッドで通知ペイロードを受け取る。

```php
use Abante4wd\Iap\Notifications\GooglePlayNotificationHandler;
use Abante4wd\Iap\Notifications\AppleServerNotificationHandler;

// Google Play Real-time Developer Notifications (RTDN)
$googleHandler = new GooglePlayNotificationHandler($deferredService);
$result = $googleHandler->handle($requestBody);

// Apple App Store Server Notifications V2
$appleHandler = new AppleServerNotificationHandler($deferredService);
$result = $appleHandler->handle($requestBody);

// 戻り値: array{type: string, action: string, details: array}
// 例: ['type' => 'ONE_TIME_PRODUCT_PURCHASED', 'action' => 'completed', 'details' => [...]]
```

**対応する通知タイプ:**

| ハンドラー | 通知タイプ | アクション |
|---|---|---|
| Google | `oneTimeProductNotification` (type=1) | 保留購入を完了 |
| Google | `oneTimeProductNotification` (type=2) | 保留購入をキャンセル |
| Google | `subscriptionNotification` | サブスク更新を記録 |
| Google | `voidedPurchaseNotification` | 購入をキャンセル |
| Apple | `ONE_TIME_CHARGE` (ACCEPTED) | 保留購入を完了 |
| Apple | `ONE_TIME_CHARGE` (DECLINED) | 保留購入をキャンセル |
| Apple | `REVOKE` | 購入をキャンセル |
| Apple | `SUBSCRIBED` / `DID_RENEW` 等 | サブスク更新を記録 |

## ホストアプリが実装するインターフェース

### PurchaseRepositoryInterface

購入記録・商品マスタへのアクセスを抽象化する。

```php
use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;

class MyPurchaseRepository implements PurchaseRepositoryInterface
{
    public function findProductByProductId(string $productId): ?ProductData { ... }
    public function findVerifiedByPlatformAndTransactionId(Platform $platform, string $txId): ?PurchaseData { ... }
    public function createFailed(...): PurchaseData { ... }
    public function createOrUpdateVerified(...): PurchaseData { ... }
    public function markAcknowledged(int|string $purchaseId): void { ... }
    public function markRewardsGranted(int|string $purchaseId): void { ... }
    public function createOrUpdatePending(...): PurchaseData { ... }        // 保留購入の作成・更新
    public function findPendingByPlatformAndToken(Platform $platform, string $token): ?PurchaseData { ... }
    public function completePending(int|string $purchaseId, string $txId, array $response): PurchaseData { ... }
    public function cancelPending(int|string $purchaseId, ?string $reason = null): void { ... }
    public function transaction(callable $callback): mixed { ... }
}
```

### SubscriptionRepositoryInterface

サブスクリプション状態の永続化を抽象化する。

```php
use Abante4wd\Iap\Contracts\SubscriptionRepositoryInterface;

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
use Abante4wd\Iap\Contracts\RewardGrantServiceInterface;

class MyRewardGrantService implements RewardGrantServiceInterface
{
    /** @return array<int, array{type: string, identifier: string|null, quantity: int}> */
    public function grant(PurchaseData $purchase): array { ... }
}
```

### ServerNotificationHandlerInterface

ストアからのサーバー通知を処理するインターフェース。

```php
use Abante4wd\Iap\Contracts\ServerNotificationHandlerInterface;

class MyNotificationHandler implements ServerNotificationHandlerInterface
{
    /** @return array{type: string, action: string, details: array} */
    public function handle(string $payload): array { ... }
}
```

パッケージには `GooglePlayNotificationHandler` と `AppleServerNotificationHandler` が組み込み実装として含まれている。

## DTO 一覧

| クラス | 用途 |
|---|---|
| `ProductData` | 商品マスタ情報。`storeProductId($platform)` でストア別商品 ID を取得 |
| `PurchaseData` | 購入トランザクション記録（`pendingReason`, `deferredAt`, `completedAt` を含む） |
| `SubscriptionData` | サブスクリプション状態（期限切れ検査用） |
| `SubscriptionInfo` | ストア API から取得したサブスクリプション情報 |
| `VerificationResult` | ストア API 検証結果（`isPending`, `pendingReason` を含む） |

## Enum 一覧

| Enum | 値 |
|---|---|
| `Platform` | `google`, `apple` |
| `PurchaseStatus` | `pending`, `deferred`, `verified`, `failed`, `cancelled`, `refunded` |
| `PendingReason` | `ask_to_buy`, `pending_payment`, `unknown` |
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

サーバー通知の受信エンドポイントも追加する:

```php
// routes/api.php
Route::post('/notifications/apple', [NotificationController::class, 'apple']);
Route::post('/notifications/google', [NotificationController::class, 'google']);
```

```php
class NotificationController extends Controller
{
    public function google(Request $request, GooglePlayNotificationHandler $handler): JsonResponse
    {
        $result = $handler->handle($request->getContent());
        return response()->json($result);
    }

    public function apple(Request $request, AppleServerNotificationHandler $handler): JsonResponse
    {
        $result = $handler->handle($request->getContent());
        return response()->json($result);
    }
}
```

### Query Builder（DB ファサード）を使った実装例

Eloquent モデルを使わず、`DB` ファサード（Query Builder）で Repository を実装することもできる。
完全な実装は `docs/laravel/app/Services/Iap/QueryBuilderPurchaseRepository.php` を参照。

```php
use Abante4wd\Iap\Contracts\PurchaseRepositoryInterface;
use Abante4wd\Iap\DTO\ProductData;
use Abante4wd\Iap\DTO\PurchaseData;
use Abante4wd\Iap\Enums\Platform;
use Abante4wd\Iap\Enums\PurchaseStatus;
use Abante4wd\Iap\Enums\PurchaseType;
use Illuminate\Support\Facades\DB;

class QueryBuilderPurchaseRepository implements PurchaseRepositoryInterface
{
    public function findProductByProductId(string $productId): ?ProductData
    {
        $row = DB::table('products')->where('product_id', $productId)->first();

        if (! $row) {
            return null;
        }

        return new ProductData(
            id: $row->id,
            productId: $row->product_id,
            name: $row->name,
            type: PurchaseType::from($row->type),
            googleProductId: $row->google_product_id,
            appleProductId: $row->apple_product_id,
        );
    }

    public function createOrUpdateVerified(
        int|string $userId,
        int|string $productId,
        Platform $platform,
        string $txId,
        string $token,
        ?string $receipt,
        array $response,
    ): PurchaseData {
        $existing = DB::table('purchases')
            ->where('platform', $platform->value)
            ->where('transaction_id', $txId)
            ->first();

        $data = [
            'user_id' => $userId,
            'product_id' => $productId,
            'purchase_token' => $token,
            'status' => PurchaseStatus::Verified->value,
            'receipt_payload' => $receipt,
            'store_response' => json_encode($response),
            'verified_at' => now(),
            'updated_at' => now(),
        ];

        if ($existing) {
            DB::table('purchases')->where('id', $existing->id)->update($data);
            $id = $existing->id;
        } else {
            $id = DB::table('purchases')->insertGetId(array_merge($data, [
                'platform' => $platform->value,
                'transaction_id' => $txId,
                'created_at' => now(),
            ]));
        }

        return $this->toDto(DB::table('purchases')->find($id));
    }

    public function findAllPendingByPlatform(Platform $platform): array
    {
        $rows = DB::table('purchases')
            ->where('platform', $platform->value)
            ->where('status', PurchaseStatus::Deferred->value)
            ->get();

        return array_map(
            fn (object $row) => $this->toDto($row),
            $rows->all(),
        );
    }

    public function transaction(callable $callback): mixed
    {
        return DB::transaction($callback);
    }

    // 他のメソッド（createFailed, markAcknowledged, findPendingByPlatformAndToken 等）も
    // 同様のパターンで実装する。完全な実装例は docs/laravel/ を参照。
    // ...
}
```

ServiceProvider でバインディングを切り替えるだけで Eloquent 版と Query Builder 版を差し替えられる:

```php
// Eloquent 版
$this->app->bind(PurchaseRepositoryInterface::class, EloquentPurchaseRepository::class);
$this->app->bind(SubscriptionRepositoryInterface::class, EloquentSubscriptionRepository::class);

// Query Builder 版
$this->app->bind(PurchaseRepositoryInterface::class, QueryBuilderPurchaseRepository::class);
$this->app->bind(SubscriptionRepositoryInterface::class, QueryBuilderSubscriptionRepository::class);
```

## テスト

```bash
vendor/bin/phpunit
```

30 件のユニットテストが含まれており、サービス層・通知ハンドラー・ファクトリをカバーしている。

### CI

GitHub Actions で PHP 8.2 / 8.3 / 8.4 に対して、`main` ブランチへの push / PR 時にテストが自動実行される。

## 依存関係

- `guzzlehttp/guzzle` ^7.0 — Apple App Store API の HTTP 通信
- `firebase/php-jwt` ^7.0 — Apple JWT 生成
- `google/apiclient` ^2.18 — Google Play Android Publisher API

**illuminate/* への依存は一切ない。**

## 公式ドキュメント

### Google Play

- [Google Play Developer API - 購入検証](https://developer.android.com/google/play/billing/integrate#verify)
- [Android Publisher API リファレンス](https://developers.google.com/android-publisher/api-ref/rest)
- [Real-time Developer Notifications (RTDN)](https://developer.android.com/google/play/billing/getting-ready#configure-rtdn)
- [サーバーサイド購入検証](https://developer.android.com/google/play/billing/security#verify)

### Apple App Store

- [App Store Server API](https://developer.apple.com/documentation/appstoreserverapi)
- [App Store Server Notifications V2](https://developer.apple.com/documentation/appstoreservernotifications)
- [JWS トランザクション情報](https://developer.apple.com/documentation/appstoreserverapi/jwstransaction)
- [In-App Purchase サーバーサイド検証](https://developer.apple.com/documentation/storekit/in-app_purchase/original_api_for_in-app_purchase/validating_receipts_with_the_app_store)
