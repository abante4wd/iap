<?php

namespace Abante4wd\Iap\Enums;

/**
 * サブスクリプションの状態を表す列挙型。
 */
enum SubscriptionStatus: string
{
    /** 有効（課金中） */
    case Active = 'active';

    /** 有効期限切れ */
    case Expired = 'expired';

    /** 解約済み */
    case Cancelled = 'cancelled';

    /** 支払い猶予期間中（決済失敗後の一時的な有効状態） */
    case GracePeriod = 'grace_period';

    /** 一時停止中 */
    case Paused = 'paused';
}
