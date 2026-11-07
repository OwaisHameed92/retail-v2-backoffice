<?php

namespace App\Domain\Cash\Support;

use App\Domain\TillData\Models\PaymentType;

/**
 * Tender kind `PaidElsewhere` (till 0.1.85, WORKSHOP-BIKEBOOK.md): the payment type "Paid in BikeBook" a till makes
 * when the shop connects BikeBook. The kind is the till's own (UPCOMING-CHANGES: `TenderKind` is in no pushed row), so
 * the portal sees an ordinary PaymentType that is not cash, never opens the drawer and carries no card, voucher,
 * points or account flag, and knows it by name. Being not cash, it is already out of cash takings, the drawer and
 * cash variance everywhere (they all read `isCash`); tender reports, cash-ups and the Z show it as its own line by
 * name. This only gives it its own words.
 */
final class PaidElsewhere
{
    public const NAMES = ['paid in bikebook', 'paid elsewhere'];

    public static function matches(?PaymentType $type, string $name): bool
    {
        if ($type !== null && ($type->is_cash || $type->is_card || $type->is_voucher || $type->is_points || $type->is_account)) {
            return false;
        }

        return in_array(mb_strtolower(trim($name)), self::NAMES, true);
    }
}
