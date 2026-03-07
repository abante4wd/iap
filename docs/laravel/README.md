# fukazawa/iap - Laravel Integration Guide

`fukazawa/iap` パッケージを Laravel プロジェクトに導入するためのサンプルファイル集です。

## ディレクトリ構成

```
docs/laravel/
├── .env.example                          # 環境変数のサンプル
├── config/
│   └── iap.php                           # 設定ファイル → config/ にコピー
├── app/
│   ├── Models/
│   │   ├── Product.php                   # 商品マスタモデル
│   │   ├── Purchase.php                  # 購入記録モデル
│   │   └── Subscription.php              # サブスクリプションモデル
│   ├── Providers/
│   │   └── IapServiceProvider.php        # サービスプロバイダ
│   ├── Services/Iap/
│   │   ├── EloquentPurchaseRepository.php
│   │   ├── EloquentSubscriptionRepository.php
│   │   └── RewardGrantService.php
│   ├── Http/Controllers/
│   │   └── PurchaseController.php        # 検証エンドポイント
│   └── Console/Commands/
│       └── CheckSubscriptionsCommand.php # サブスク状態チェックコマンド
├── database/migrations/
│   ├── 2024_01_01_000001_create_products_table.php
│   ├── 2024_01_01_000002_create_purchases_table.php
│   └── 2024_01_01_000003_create_subscriptions_table.php
└── routes/
    └── api.php                           # API ルート定義
```

## 導入手順

### 1. パッケージをインストール

```bash
composer require fukazawa/iap
```

プライベートリポジトリの場合は `composer.json` に `repositories` を追加:

```json
{
    "repositories": [
        {
            "type": "vcs",
            "url": "git@github.com:fukazawa/iap.git"
        }
    ]
}
```

#### ZIP ファイルから導入する場合

Git アクセス権がない場合は、ZIP 配布からインストールできます。

1. ZIP を展開し、Laravel プロジェクト内の `packages/iap/` に配置します:

```
your-laravel-project/
├── packages/
│   └── iap/          ← ZIP の中身をここに展開
│       ├── composer.json
│       └── src/
├── app/
├── composer.json
└── ...
```

2. プロジェクトの `composer.json` に `path` リポジトリを追加します:

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

3. パッケージをインストールします:

```bash
composer require fukazawa/iap:*
```

### 2. ファイルをコピー

このディレクトリのファイルを Laravel プロジェクトの対応するパスにコピーしてください。

### 3. 環境変数を設定

`.env.example` を参考に、`.env` に IAP 関連の値を設定してください。

### 4. サービスプロバイダを登録

`bootstrap/providers.php` に追加:

```php
return [
    // ...
    App\Providers\IapServiceProvider::class,
];
```

または `AppServiceProvider` の `register()` に直接バインディングを記述することもできます。

### 5. マイグレーションを実行

```bash
php artisan migrate
```

### 6. 商品マスタを登録

`products` テーブルに商品データを登録:

```php
Product::create([
    'product_id' => 'gem_100',
    'name' => 'Gem 100 Pack',
    'type' => 'consumable',
    'google_product_id' => 'com.example.myapp.gem_100',
    'apple_product_id' => 'com.example.myapp.gem100',
]);
```

### 7. 報酬付与ロジックを実装

`app/Services/Iap/RewardGrantService.php` の `grant()` メソッドにアプリ固有の報酬付与ロジックを実装してください。

### 8. サブスクリプションチェックの定期実行（任意）

`routes/console.php` にスケジュールを登録:

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('iap:check-subscriptions')->hourly();
```

## API エンドポイント

### POST /api/purchases/verify

購入トークンを検証し、報酬を付与します。

**Request:**
```json
{
    "platform": "google",
    "product_id": "gem_100",
    "purchase_token": "purchase-token-from-store",
    "receipt_data": null
}
```

**Response (200):**
```json
{
    "success": true,
    "purchase_id": 1,
    "rewards": [
        {"type": "currency", "identifier": "gem", "quantity": 100}
    ]
}
```
