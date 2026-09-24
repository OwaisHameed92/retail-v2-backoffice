<?php

namespace App\Domain\Shared\Casts;

use App\Domain\Shared\Support\Money;

/**
 * For `decimal(14,4)` columns (costs, quantities): `'qty' => QuantityCast::class` → "1.2500".
 */
final class QuantityCast extends DecimalCast
{
    protected function scale(): int
    {
        return Money::QUANTITY_SCALE;
    }
}
