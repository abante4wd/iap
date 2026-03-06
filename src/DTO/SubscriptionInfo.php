<?php

namespace Fukazawa\Iap\DTO;

class SubscriptionInfo
{
    public function __construct(
        public readonly string $originalTransactionId,
        public readonly string $currentTransactionId,
        public readonly \DateTimeImmutable $startsAt,
        public readonly \DateTimeImmutable $expiresAt,
        public readonly bool $autoRenewing,
        public readonly string $status,
    ) {}
}
