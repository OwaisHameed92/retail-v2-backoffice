<?php

namespace App\Domain\Stock\Support;

/**
 * The till's stock movement types (`StockMovement.type`) grouped the way a shop owner reads them (module 5.1): the
 * movements filter offers the groups; each row shows its own type's label.
 */
final class MovementKinds
{
    /** Filter group => the movement types in it. */
    public const GROUPS = [
        'sales' => ['sale', 'refund'],
        'goodsIn' => ['received', 'supplierReturn'],
        'adjustments' => ['adjustment', 'openingStock', 'promotionSample'],
        'transfers' => ['transfer'],
        'wastage' => ['wastage', 'damaged', 'expiry', 'theft'],
        'stockTakes' => ['stockTake'],
    ];

    public const GROUP_LABELS = [
        'sales' => 'Sales and refunds',
        'goodsIn' => 'Goods in and returns',
        'adjustments' => 'Adjustments',
        'transfers' => 'Transfers',
        'wastage' => 'Wastage',
        'stockTakes' => 'Stock takes',
    ];

    public const LABELS = [
        'sale' => 'Sale',
        'refund' => 'Refund',
        'adjustment' => 'Adjustment',
        'received' => 'Goods in',
        'wastage' => 'Wastage',
        'transfer' => 'Transfer',
        'stockTake' => 'Stock take',
        'damaged' => 'Damaged',
        'supplierReturn' => 'Returned to supplier',
        'expiry' => 'Out of date',
        'theft' => 'Theft',
        'openingStock' => 'Opening stock',
        'promotionSample' => 'Promotion sample',
    ];

    /** Types that are stock lost (wastage report). */
    public const WASTAGE = ['wastage', 'damaged', 'expiry', 'theft'];

    public static function label(?string $type): string
    {
        return self::LABELS[$type ?? ''] ?? ($type !== null && $type !== '' ? ucfirst($type) : 'Movement');
    }

    public static function group(?string $type): ?string
    {
        foreach (self::GROUPS as $group => $types) {
            if (in_array($type, $types, true)) {
                return $group;
            }
        }

        return null;
    }

    /** @return list<array{value: string, label: string}> */
    public static function options(): array
    {
        return array_map(fn (string $g) => ['value' => $g, 'label' => self::GROUP_LABELS[$g]], array_keys(self::GROUPS));
    }
}
