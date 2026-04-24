<?php

namespace Abante4wd\Iap\Enums;

enum PurchaseType: string
{
    case Consumable = 'consumable';
    case NonConsumable = 'non_consumable';
    case Subscription = 'subscription';
}
