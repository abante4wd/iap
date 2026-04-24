<?php

namespace Abante4wd\Iap\Store;

use Abante4wd\Iap\Contracts\StoreVerifierInterface;
use Abante4wd\Iap\Enums\Platform;

/**
 * プラットフォームに対応する StoreVerifier インスタンスを提供するファクトリー。
 *
 * DI コンテナから注入されたベリファイアを返すだけの薄いラッパーであり、
 * サービスクラスが Platform に依存せず検証処理を呼び出せるようにする。
 */
class StoreVerifierFactory
{
    /**
     * @param GooglePlayVerifier $googleVerifier Google Play 検証インスタンス
     * @param AppleStoreVerifier $appleVerifier  App Store 検証インスタンス
     */
    public function __construct(
        private GooglePlayVerifier $googleVerifier,
        private AppleStoreVerifier $appleVerifier,
    ) {}

    /**
     * 指定プラットフォームに対応する StoreVerifier を返す。
     *
     * @param Platform $platform 対象プラットフォーム
     * @return StoreVerifierInterface プラットフォーム固有のベリファイア
     */
    public function make(Platform $platform): StoreVerifierInterface
    {
        return match ($platform) {
            Platform::Google => $this->googleVerifier,
            Platform::Apple => $this->appleVerifier,
        };
    }
}
