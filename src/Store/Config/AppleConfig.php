<?php

namespace Abante4wd\Iap\Store\Config;

/**
 * Apple App Store の検証に必要な設定値を保持する読み取り専用 DTO。
 *
 * App Store Connect の "Keys" セクションで発行した API キー情報を設定する。
 */
readonly class AppleConfig
{
    /**
     * @param string $bundleId       アプリの Bundle ID（例: com.example.myapp）
     * @param string $issuerId       App Store Connect の Issuer ID（UUID 形式）
     * @param string $keyId          API キーの Key ID（例: ABCD1234EF）
     * @param string $privateKeyPath .p8 秘密鍵ファイルの絶対パス
     * @param string $environment    接続環境。'production' または 'sandbox'（デフォルト: 'sandbox'）
     */
    public function __construct(
        public string $bundleId,
        public string $issuerId,
        public string $keyId,
        public string $privateKeyPath,
        public string $environment = 'sandbox',
    ) {}
}
