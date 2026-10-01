<?php

namespace App\Domain\Shared\Support;

/**
 * Text that people typed (names, notes), made safe for a CSV that opens in Excel: a value starting with `=`, `+`,
 * `-`, `@`, tab or carriage return gets an apostrophe in front, so it is never run as a formula (CSV injection).
 */
final class CsvText
{
    public static function safe(mixed $value): string
    {
        $value = (string) $value;

        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
