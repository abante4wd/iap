<?php

namespace Abante4wd\Iap\Exceptions;

use Exception;

/**
 * ストア検証が失敗した場合にスローされる例外。
 *
 * 商品が見つからない、またはストア API から不正な応答を受け取った場合に発生する。
 */
class StoreVerificationFailedException extends Exception
{
    //
}
