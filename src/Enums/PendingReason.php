<?php

namespace Abante4wd\Iap\Enums;

/**
 * 購入が保留中となっている理由を表す列挙型。
 */
enum PendingReason: string
{
    /** Apple: 保護者の承認待ち（Ask to Buy） */
    case AskToBuy = 'ask_to_buy';

    /** Google Play: 支払い処理が保留中 */
    case PendingPayment = 'pending_payment';

    /** 理由が特定できない保留 */
    case Unknown = 'unknown';
}
