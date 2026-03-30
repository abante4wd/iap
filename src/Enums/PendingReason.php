<?php

namespace Fukazawa\Iap\Enums;

enum PendingReason: string
{
    case AskToBuy = 'ask_to_buy';           // Apple: 保護者の承認待ち
    case PendingPayment = 'pending_payment'; // Google Play: 支払い保留
    case Unknown = 'unknown';
}
