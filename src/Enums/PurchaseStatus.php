<?php

namespace Abante4wd\Iap\Enums;

/**
 * 購入レコードの状態を表す列挙型。
 */
enum PurchaseStatus: string
{
    /** 処理中（未確定） */
    case Pending = 'pending';

    /** 保留中（Apple Ask to Buy の承認待ち等） */
    case Deferred = 'deferred';

    /** ストア検証済み */
    case Verified = 'verified';

    /** 検証失敗 */
    case Failed = 'failed';

    /** 拒否またはキャンセル */
    case Cancelled = 'cancelled';

    /** 返金済み */
    case Refunded = 'refunded';
}
