<?php

namespace Abante4wd\Iap\Store\Config;

/**
 * Google Play の検証に必要な設定値を保持する読み取り専用 DTO。
 *
 * Google Cloud のサービスアカウント認証情報と Google Play Console のパッケージ名を設定する。
 */
readonly class GoogleConfig
{
    /**
     * @param string $applicationName        Google API クライアントに設定するアプリケーション名
     * @param string $serviceAccountJsonPath サービスアカウントの JSON 鍵ファイルの絶対パス
     * @param string $packageName            Android アプリのパッケージ名（例: com.example.myapp）
     */
    public function __construct(
        public string $applicationName,
        public string $serviceAccountJsonPath,
        public string $packageName,
    ) {}
}
