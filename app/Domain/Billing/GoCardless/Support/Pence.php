<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Shared\Support\Money;

/**
 * GoCardless counts pence (integers); our records are pounds as decimal strings. bcmath only, never floats.
 */
final class Pence
{
    public static function fromPounds(string $pounds): int
    {
        return (int) bcmul(Money::normalise($pounds), '100', 0);
    }

    public static function toPounds(int $pence): string
    {
        return bcdiv((string) $pence, '100', 2);
    }
}
