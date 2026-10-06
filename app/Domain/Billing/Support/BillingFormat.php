<?php

namespace App\Domain\Billing\Support;

use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;

/**
 * Money formatting without floats: "1234.5" → "£1,234.50", "-3" → "-£3.00" (GB; PK "Rs 1,235", "-Rs 3").
 */
final class BillingFormat
{
    /** The country profile's money (MoneyFormat): GB output as before. */
    public static function money(mixed $amount): string
    {
        return MoneyFormat::format($amount);
    }

    /** "20.00" → "20%", "17.50" → "17.5%". */
    public static function percent(string $rate): string
    {
        $trimmed = rtrim(rtrim(Money::normalise($rate), '0'), '.');

        return $trimmed.'%';
    }

    /** "1.0000" → "1", "0.5000" → "0.5". */
    public static function quantity(string $quantity): string
    {
        $trimmed = rtrim(rtrim(Money::normalise($quantity, Money::QUANTITY_SCALE), '0'), '.');

        return $trimmed === '' ? '0' : $trimmed;
    }
}
