<?php

namespace App\Domain\Setup\Support;

use App\Domain\TillData\Models\PaymentType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Payment types are made per shop by the tills (till 0.1.15, PORTAL-CHANGES-0.1.15 item 3): each shop's till adds
 * its own "Order deposit" and "Loyalty points" row with its own id, so a business with two shops holds two rows of
 * each. The contract says: store by id, group by name for company-wide lists. The portal lists one line per name
 * (case-insensitive), and a change to that line is made to every row of the group. Call inside the company scope.
 */
final class PaymentTypeGroup
{
    /**
     * Made by every shop's till on its first start and needed by the till's order deposit and loyalty flows: the
     * name is kept (a renamed row would split from the one a new shop's till makes) and the type is never removed.
     */
    public const TILL_SYSTEM_NAMES = ['order deposit', 'loyalty points'];

    public static function key(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    public static function isTillSystem(string $name): bool
    {
        return in_array(self::key($name), self::TILL_SYSTEM_NAMES, true);
    }

    /**
     * Every row with the same name as the given one (the row itself included), oldest id first.
     *
     * @return Collection<int, PaymentType>
     */
    public static function members(PaymentType $type): Collection
    {
        return PaymentType::query()->whereRaw('LOWER(name) = ?', [self::key($type->name)])->orderBy('id')->get();
    }

    /**
     * One row per name: the one with the lowest id stands for its group in lists.
     *
     * @return Builder<PaymentType>
     */
    public static function leaders(): Builder
    {
        return PaymentType::query()->whereIn('id', PaymentType::query()->selectRaw('MIN(id)')->groupByRaw('LOWER(name)'));
    }
}
