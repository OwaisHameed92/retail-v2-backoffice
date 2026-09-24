<?php

namespace App\Domain\Shared\Casts;

use App\Domain\Shared\Support\Money;

/**
 * For `decimal(12,2)` columns (prices, totals): `'price' => MoneyCast::class` → "1.45".
 */
final class MoneyCast extends DecimalCast
{
    protected function scale(): int
    {
        return Money::SCALE;
    }
}
