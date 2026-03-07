<?php

namespace App\Console\Commands;

use Fukazawa\Iap\Services\SubscriptionCheckService;
use Illuminate\Console\Command;

class CheckSubscriptionsCommand extends Command
{
    protected $signature = 'iap:check-subscriptions';

    protected $description = '期限切れ間近のサブスクリプションの状態をストアに問い合わせて更新する';

    public function handle(SubscriptionCheckService $checker): int
    {
        $result = $checker->checkExpiring(
            onProgress: fn (string $message) => $this->info($message),
        );

        $this->table(
            ['Checked', 'Updated', 'Errors'],
            [[$result['checked'], $result['updated'], $result['errors']]],
        );

        return $result['errors'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
