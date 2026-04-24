<?php

namespace Abante4wd\Iap\Enums;

/**
 * アプリ内課金の購入種別を表す列挙型。
 */
enum PurchaseType: string
{
    /** 消耗品（ゲームコイン等、購入ごとに消費される） */
    case Consumable = 'consumable';

    /** 非消耗品（機能アンロック等、一度購入すれば永続） */
    case NonConsumable = 'non_consumable';

    /** サブスクリプション（定期課金） */
    case Subscription = 'subscription';
}
