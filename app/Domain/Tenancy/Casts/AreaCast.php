<?php

namespace App\Domain\Tenancy\Casts;

use App\Domain\Shared\Casts\DecimalCast;

/**
 * Floor area in square metres, `decimal(10,2)`, as a normalised string ("85.50"). Never a float.
 */
final class AreaCast extends DecimalCast
{
    protected function scale(): int
    {
        return 2;
    }
}
