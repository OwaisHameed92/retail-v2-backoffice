<?php

namespace App\Domain\Licensing\Enums;

/**
 * How far a renewal goes: one month, one year, or until a chosen date.
 */
enum RenewalPeriod: string
{
    case Month = 'month';
    case Year = 'year';
    case Until = 'until';

    public function label(): string
    {
        return match ($this) {
            self::Month => '1 month',
            self::Year => '1 year',
            self::Until => 'Until a date',
        };
    }
}
