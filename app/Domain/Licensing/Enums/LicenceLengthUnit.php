<?php

namespace App\Domain\Licensing\Enums;

use Carbon\CarbonImmutable;

/**
 * The unit of a branch's licence length (contract §17.2: "validFrom…expiresAt is the length the admin chose
 * (days, months, years)"). Days are exact 24-hour periods; months and years never overflow (31 Jan + 1 month =
 * 28/29 Feb).
 */
enum LicenceLengthUnit: string
{
    case Days = 'days';
    case Months = 'months';
    case Years = 'years';

    public function add(CarbonImmutable $from, int $length): CarbonImmutable
    {
        return match ($this) {
            self::Days => $from->addDays($length),
            self::Months => $from->addMonthsNoOverflow($length),
            self::Years => $from->addYearsNoOverflow($length),
        };
    }

    public function describe(int $length): string
    {
        $unit = match ($this) {
            self::Days => 'day',
            self::Months => 'month',
            self::Years => 'year',
        };

        return $length.' '.($length === 1 ? $unit : $unit.'s');
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $unit) => ['value' => $unit->value, 'label' => ucfirst($unit->value)], self::cases());
    }
}
