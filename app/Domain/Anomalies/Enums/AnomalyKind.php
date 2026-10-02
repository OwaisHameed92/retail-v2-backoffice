<?php

namespace App\Domain\Anomalies\Enums;

/**
 * What kind of unusual activity a detector found (module 6.6). camelCase values. Staff-level kinds name a till user
 * and are shown only to owners and managers (privacy); the rest are about a shop or a till.
 */
enum AnomalyKind: string
{
    case StaffVoids = 'staffVoids';
    case StaffRefunds = 'staffRefunds';
    case StaffNoSales = 'staffNoSales';
    case StaffDiscounts = 'staffDiscounts';
    case StaffCashShortfalls = 'staffCashShortfalls';
    case TillCashShortfalls = 'tillCashShortfalls';
    case SalesGap = 'salesGap';
    case SalesDrop = 'salesDrop';
    case PriceOverrides = 'priceOverrides';
    case NegativeStock = 'negativeStock';
    case UnlinkedRefunds = 'unlinkedRefunds';
    case OutOfHours = 'outOfHours';

    public function label(): string
    {
        return match ($this) {
            self::StaffVoids => 'Voids by one staff member',
            self::StaffRefunds => 'Refunds by one staff member',
            self::StaffNoSales => 'No-sale drawer opens by one staff member',
            self::StaffDiscounts => 'Manual discounts by one staff member',
            self::StaffCashShortfalls => 'Repeated cash shortfalls (staff member)',
            self::TillCashShortfalls => 'Repeated cash shortfalls (till)',
            self::SalesGap => 'No sales for several hours',
            self::SalesDrop => 'Sales well below normal',
            self::PriceOverrides => 'Price overrides and manual discounts',
            self::NegativeStock => 'Products going below zero',
            self::UnlinkedRefunds => 'Refunds without the original sale',
            self::OutOfHours => 'Sales outside opening hours',
        };
    }

    /** About one till user: owners and managers only. */
    public function staffLevel(): bool
    {
        return match ($this) {
            self::StaffVoids, self::StaffRefunds, self::StaffNoSales, self::StaffDiscounts, self::StaffCashShortfalls => true,
            default => false,
        };
    }

    /**
     * Days a repeat of the same kind for the same shop and subject updates the open row instead of raising a new one.
     * Zero: one row per trading day.
     */
    public function coolDownDays(): int
    {
        return match ($this) {
            self::StaffVoids, self::StaffRefunds, self::StaffNoSales, self::StaffDiscounts => 7,
            self::StaffCashShortfalls, self::TillCashShortfalls => 14,
            self::PriceOverrides, self::NegativeStock, self::UnlinkedRefunds => 3,
            self::SalesGap, self::SalesDrop, self::OutOfHours => 0,
        };
    }

    /**
     * @return list<array{value: string, label: string, staffLevel: bool}>
     */
    public static function options(bool $withStaff): array
    {
        return array_values(array_map(
            fn (self $k) => ['value' => $k->value, 'label' => $k->label(), 'staffLevel' => $k->staffLevel()],
            array_filter(self::cases(), fn (self $k) => $withStaff || ! $k->staffLevel()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function shopLevelValues(): array
    {
        return array_values(array_map(fn (self $k) => $k->value, array_filter(self::cases(), fn (self $k) => ! $k->staffLevel())));
    }
}
