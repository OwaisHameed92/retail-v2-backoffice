<?php

namespace App\Domain\Billing\Enums;

use Carbon\CarbonImmutable;

/**
 * How often a company is invoiced. Plans price every till per month and per year.
 */
enum BillingCycle: string
{
    case Monthly = 'monthly';
    case Yearly = 'yearly';

    public function label(): string
    {
        return match ($this) {
            self::Monthly => 'Monthly',
            self::Yearly => 'Yearly',
        };
    }

    /** "per month" / "per year" */
    public function per(): string
    {
        return match ($this) {
            self::Monthly => 'per month',
            self::Yearly => 'per year',
        };
    }

    /**
     * Last day of a period that starts on `$start` (calendar dates): one month or year later, minus a day.
     * 1 Nov → 30 Nov, 1 Mar → 31 Mar, 15 Oct → 14 Nov, 1 Oct 2026 → 30 Sep 2027. Starts after the 28th count
     * from the day before so short months never overflow: 31 Jan → 28 Feb (29 in a leap year), 30 Mar → 29 Apr.
     */
    public function periodEnd(CarbonImmutable $start): CarbonImmutable
    {
        $add = fn (CarbonImmutable $date) => $this === self::Monthly ? $date->addMonthNoOverflow() : $date->addYearNoOverflow();

        return $start->day > 28 ? $add($start->subDay()) : $add($start)->subDay();
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $cycle) => ['value' => $cycle->value, 'label' => $cycle->label()], self::cases());
    }
}
