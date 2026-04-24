<?php

namespace Abante4wd\Iap\Exceptions;

use Exception;

/**
 * すでに検証済みの購入を再度検証しようとした場合にスローされる例外。
 *
 * 二重付与を防ぐため、同一トランザクション ID が検証済みとして存在する場合に発生する。
 */
class PurchaseAlreadyVerifiedException extends Exception
{
    //
}
