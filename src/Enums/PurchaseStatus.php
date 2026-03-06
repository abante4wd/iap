<?php

namespace Fukazawa\Iap\Enums;

enum PurchaseStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case Refunded = 'refunded';
}
