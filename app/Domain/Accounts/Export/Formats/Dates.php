<?php

namespace App\Domain\Accounts\Export\Formats;

/** UK dates for the packages' import files. */
final class Dates
{
    /** "2026-09-10" → "10/09/2026". */
    public static function uk(string $day): string
    {
        [$y, $m, $d] = explode('-', substr($day, 0, 10));

        return "{$d}/{$m}/{$y}";
    }
}
