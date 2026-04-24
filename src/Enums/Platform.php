<?php

namespace Abante4wd\Iap\Enums;

/**
 * IAP の購入プラットフォームを表す列挙型。
 */
enum Platform: string
{
    /** Google Play Store */
    case Google = 'google';

    /** Apple App Store */
    case Apple = 'apple';
}
