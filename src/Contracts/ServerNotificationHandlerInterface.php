<?php

namespace Abante4wd\Iap\Contracts;

/**
 * Apple / Google からのサーバー通知を処理するハンドラーのインターフェース。
 */
interface ServerNotificationHandlerInterface
{
    /**
     * ストアからのサーバー通知を処理する。
     *
     * @param string $payload ストアから受信した生のリクエストボディ（JSON 文字列）
     * @return array{type: string, action: string, details: array} 処理結果
     */
    public function handle(string $payload): array;
}
