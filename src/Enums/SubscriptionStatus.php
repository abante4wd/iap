<?php

namespace Abante4wd\Iap\Enums;

enum SubscriptionStatus: string
{
    case Active = 'active';
    case Expired = 'expired';
    case Cancelled = 'cancelled';
    case GracePeriod = 'grace_period';
    case Paused = 'paused';
}
