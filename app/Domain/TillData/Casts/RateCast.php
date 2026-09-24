<?php

namespace App\Domain\TillData\Casts;

use App\Domain\Shared\Casts\DecimalCast;

/**
 * For `decimal(16,6)` exchange-rate columns: "1.173400". Same rules as MoneyCast (string, never float).
 */
final class RateCast extends DecimalCast
{
    public const SCALE = 6;

    protected function scale(): int
    {
        return self::SCALE;
    }
}
