<?php

namespace Fukazawa\Iap\DTO;

readonly class SubscriptionInfo
{
    public function __construct(
        public string $originalTransactionId,
        public string $currentTransactionId,
        public \DateTimeImmutable $startsAt,
        public \DateTimeImmutable $expiresAt,
        public bool $autoRenewing,
        public string $status,
    ) {}
}
