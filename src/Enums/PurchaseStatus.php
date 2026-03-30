<?php

namespace Fukazawa\Iap\Enums;

enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Deferred = 'deferred';     // Apple Ask to Buy の承認待ち
    case Verified = 'verified';
    case Failed = 'failed';
    case Cancelled = 'cancelled';   // 拒否・キャンセル
    case Refunded = 'refunded';
}
