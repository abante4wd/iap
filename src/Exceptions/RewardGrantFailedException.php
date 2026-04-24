<?php

namespace Abante4wd\Iap\Exceptions;

use Exception;

/**
 * 報酬付与処理が失敗した場合にスローされる例外。
 *
 * RewardGrantServiceInterface::grant() の実装がスローする想定。
 */
class RewardGrantFailedException extends Exception
{
    //
}
