# VerificationResult レスポンス仕様

ストア検証（`verifyProduct` / `verifySubscription`）が返す `VerificationResult` の内容をまとめる。

## DTO 定義

### VerificationResult

| プロパティ | 型 | 説明 |
|---|---|---|
| `isValid` | `bool` | 検証成功なら `true` |
| `transactionId` | `string` | ストアのトランザクション/注文ID |
| `productId` | `string` | 商品ID |
| `rawResponse` | `array` | ストア API の生レスポンス |
| `errorMessage` | `?string` | 失敗時のエラーメッセージ。正常時は `null` |
| `subscriptionInfo` | `?SubscriptionInfo` | サブスクリプション検証時のみ。商品検証時は `null` |

### SubscriptionInfo

| プロパティ | 型 | 説明 |
|---|---|---|
| `originalTransactionId` | `string` | 初回購入のトランザクションID |
| `currentTransactionId` | `string` | 現在の更新期間のトランザクションID |
| `startsAt` | `DateTimeImmutable` | サブスクリプション開始日時 |
| `expiresAt` | `DateTimeImmutable` | 有効期限 |
| `autoRenewing` | `bool` | 自動更新が有効か |
| `status` | `string` | `active` / `expired` / `cancelled` / `grace_period` / `paused` |

---

## Google Play

### verifyProduct — 正常時 (`isValid: true`)

`purchaseState === 0`（購入済み）の場合に返る。

```
VerificationResult {
    isValid:          true
    transactionId:    "GPA.1234-5678-9012-34567"  ← getOrderId()
    productId:        "com.example.myapp.gem_100"  ← 引数の productId がそのまま
    rawResponse:      [                            ← purchases.products.get の全フィールド
        "orderId"           => "GPA.1234-5678-9012-34567",
        "purchaseState"     => 0,
        "consumptionState"  => 0,
        "purchaseTimeMillis" => "1709827200000",
        "acknowledgementState" => 1,
        ...
    ]
    errorMessage:     null
    subscriptionInfo: null
}
```

### verifyProduct — 異常時 (`isValid: false`)

#### パターン 1: purchaseState が 0 以外（キャンセル・保留等）

API 通信は成功したが、購入状態が「購入済み」ではない場合。

```
VerificationResult {
    isValid:          false
    transactionId:    "GPA.1234-5678-9012-34567"  ← getOrderId() (取得できれば)
    productId:        "com.example.myapp.gem_100"
    rawResponse:      [ ... API レスポンス全体 ... ]
    errorMessage:     "Purchase state is not purchased: 1"
    subscriptionInfo: null
}
```

#### パターン 2: API 例外（認証エラー、無効トークン等）

Google API クライアントが例外をスローした場合。

```
VerificationResult {
    isValid:          false
    transactionId:    ""                           ← 空文字
    productId:        "com.example.myapp.gem_100"
    rawResponse:      []                           ← 空配列
    errorMessage:     "Error calling GET https://..."  ← 例外メッセージ
    subscriptionInfo: null
}
```

---

### verifySubscription — 正常時 (`isValid: true`)

`subscriptionState` が `SUBSCRIPTION_STATE_EXPIRED` **以外** の場合に返る。

```
VerificationResult {
    isValid:          true
    transactionId:    "GPA.1234-5678-9012-34567..0"  ← getLatestOrderId()
    productId:        "com.example.myapp.monthly_premium"
    rawResponse:      [ ... subscriptionsv2.get の全フィールド ... ]
    errorMessage:     null
    subscriptionInfo: SubscriptionInfo {
        originalTransactionId: "GPA.1234-5678-9012-34567..0"
        currentTransactionId:  "GPA.1234-5678-9012-34567..0"
        startsAt:              2026-01-01 00:00:00       ← getStartTime()
        expiresAt:             2026-04-01 00:00:00       ← lineItems[0].expiryTime
        autoRenewing:          true                      ← autoRenewingPlan.autoRenewEnabled
        status:                "active"                  ← subscriptionState のマッピング
    }
}
```

**status マッピング:**

| Google API の subscriptionState | status 値 |
|---|---|
| `SUBSCRIPTION_STATE_ACTIVE` | `active` |
| `SUBSCRIPTION_STATE_CANCELED` | `cancelled` |
| `SUBSCRIPTION_STATE_IN_GRACE_PERIOD` | `grace_period` |
| `SUBSCRIPTION_STATE_ON_HOLD` | `paused` |
| `SUBSCRIPTION_STATE_PAUSED` | `paused` |

### verifySubscription — 異常時 (`isValid: false`)

#### パターン 1: サブスクリプション期限切れ

API 通信は成功したが `subscriptionState` が `SUBSCRIPTION_STATE_EXPIRED` の場合。
`subscriptionInfo` は **セットされる**（status は `expired`）が、`isValid` は `false` になる。

```
VerificationResult {
    isValid:          false
    transactionId:    "GPA.1234-5678-9012-34567..0"
    productId:        "com.example.myapp.monthly_premium"
    rawResponse:      [ ... API レスポンス全体 ... ]
    errorMessage:     null                             ← エラーメッセージなし
    subscriptionInfo: SubscriptionInfo {
        ...
        status: "expired"
    }
}
```

#### パターン 2: API 例外

```
VerificationResult {
    isValid:          false
    transactionId:    ""
    productId:        "com.example.myapp.monthly_premium"
    rawResponse:      []
    errorMessage:     "Error calling GET https://..."
    subscriptionInfo: null
}
```

---

## Apple App Store

### verifyProduct — 正常時 (`isValid: true`)

StoreKit 2 API からトランザクション情報を取得し、Bundle ID が一致した場合に返る。

```
VerificationResult {
    isValid:          true
    transactionId:    "1000000123456789"               ← JWS payload の transactionId
    productId:        "com.example.myapp.gem100"       ← JWS payload の productId
    rawResponse:      [                                ← Apple API レスポンス
        "signedTransactionInfo" => "eyJ...",
    ]
    errorMessage:     null
    subscriptionInfo: null
}
```

### verifyProduct — 異常時 (`isValid: false`)

#### パターン 1: HTTP ステータスエラー（401, 404 等）

```
VerificationResult {
    isValid:          false
    transactionId:    "1000000123456789"               ← 引数の transactionId
    productId:        "com.example.myapp.gem100"
    rawResponse:      [ ... エラーレスポンス ... ]
    errorMessage:     "Apple API returned status: 404"
    subscriptionInfo: null
}
```

#### パターン 2: signedTransactionInfo が存在しない

```
VerificationResult {
    isValid:          false
    transactionId:    "1000000123456789"
    productId:        "com.example.myapp.gem100"
    rawResponse:      [ ... ]
    errorMessage:     "No signed transaction info in response"
    subscriptionInfo: null
}
```

#### パターン 3: Bundle ID 不一致

```
VerificationResult {
    isValid:          false
    transactionId:    "1000000123456789"
    productId:        "com.example.myapp.gem100"
    rawResponse:      [ ... ]
    errorMessage:     "Bundle ID mismatch"
    subscriptionInfo: null
}
```

#### パターン 4: 例外（ネットワークエラー、JWS デコード失敗等）

```
VerificationResult {
    isValid:          false
    transactionId:    "1000000123456789"               ← 引数の transactionId
    productId:        "com.example.myapp.gem100"
    rawResponse:      []
    errorMessage:     "cURL error 28: Connection timed out"  ← 例外メッセージ
    subscriptionInfo: null
}
```

---

### verifySubscription — 正常時 (`isValid: true`)

Bundle ID が一致し、有効期限内の場合に返る。

```
VerificationResult {
    isValid:          true
    transactionId:    "1000000123456789"               ← JWS payload の transactionId
    productId:        "com.example.myapp.monthly.premium"  ← JWS payload の productId
    rawResponse:      [ "signedTransactionInfo" => "eyJ..." ]
    errorMessage:     null
    subscriptionInfo: SubscriptionInfo {
        originalTransactionId: "1000000123456780"      ← originalTransactionId
        currentTransactionId:  "1000000123456789"      ← transactionId
        startsAt:              2026-01-01 00:00:00     ← originalPurchaseDate / purchaseDate
        expiresAt:             2026-04-01 00:00:00     ← expiresDate
        autoRenewing:          true                    ← !isUpgraded (デフォルト true)
        status:                "active"                ← expiresAt > now ? "active" : "expired"
    }
}
```

### verifySubscription — 異常時 (`isValid: false`)

商品検証と同じ 4 パターン（HTTP エラー、signedTransactionInfo なし、Bundle ID 不一致、例外）に加え、以下がある。

#### パターン 5: 有効期限切れ

API 通信・検証は成功したが `expiresAt` が現在日時より前の場合。
**注意:** Apple の場合、期限切れは `subscriptionInfo` 付きで `isValid: true` として返され、
`subscriptionInfo.status` が `expired` になる。`isValid` の判定は `expiresAt > now` ではなく
Bundle ID 一致のみで行われるため、呼び出し側で `subscriptionInfo.status` を確認する必要がある。

```
VerificationResult {
    isValid:          true                             ← Bundle ID 一致なので true
    transactionId:    "1000000123456789"
    productId:        "com.example.myapp.monthly.premium"
    rawResponse:      [ ... ]
    errorMessage:     null
    subscriptionInfo: SubscriptionInfo {
        ...
        expiresAt: 2025-12-31 23:59:59                ← 過去の日時
        status:    "expired"
    }
}
```

---

## 判定フローまとめ

```
verifyProduct / verifySubscription
  │
  ├─ 例外発生 → isValid: false, errorMessage: 例外メッセージ, rawResponse: []
  │
  └─ API 通信成功
      │
      ├─ [Google 商品]  purchaseState !== 0      → isValid: false, errorMessage あり
      ├─ [Google サブスク] state == EXPIRED       → isValid: false, subscriptionInfo あり (status: expired)
      ├─ [Apple] HTTP ステータスエラー            → isValid: false, errorMessage あり
      ├─ [Apple] signedTransactionInfo なし       → isValid: false, errorMessage あり
      ├─ [Apple] Bundle ID 不一致                 → isValid: false, errorMessage あり
      │
      └─ 検証成功
          ├─ [商品]      → isValid: true, subscriptionInfo: null
          └─ [サブスク]  → isValid: true, subscriptionInfo あり
```

---

## 参照元ソースコード

| ファイル | 説明 |
|---|---|
| `src/DTO/VerificationResult.php` | レスポンス DTO |
| `src/DTO/SubscriptionInfo.php` | サブスクリプション情報 DTO |
| `src/Store/GooglePlayVerifier.php` | Google Play 検証実装 |
| `src/Store/AppleStoreVerifier.php` | Apple App Store 検証実装 |
| `src/Contracts/StoreVerifierInterface.php` | 検証インターフェース |
