<?php

namespace App\Domain\Ai\Support;

use App\Domain\Shared\Casts\DecimalCast;

/**
 * AI cost in pounds, 6 decimal places (one call costs fractions of a penny). String, never float.
 */
final class CostCast extends DecimalCast
{
    public const SCALE = 6;

    protected function scale(): int
    {
        return self::SCALE;
    }
}
