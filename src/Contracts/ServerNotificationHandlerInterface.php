<?php

namespace Fukazawa\Iap\Contracts;

interface ServerNotificationHandlerInterface
{
    /**
     * ストアからのサーバー通知を処理する
     *
     * @return array{type: string, action: string, details: array}
     */
    public function handle(string $payload): array;
}
