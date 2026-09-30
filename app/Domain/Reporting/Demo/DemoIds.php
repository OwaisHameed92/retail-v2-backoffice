<?php

namespace App\Domain\Reporting\Demo;

use DateTimeInterface;

/**
 * Deterministic ULIDs for demo sales (`demo:sales`): the time part is the row's own time (so ids sort like the
 * till's), the random part comes from a seed. The same seed gives the same id, so running the command twice
 * stores nothing twice (the apply path sees the same ids and versions).
 */
final class DemoIds
{
    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function at(DateTimeInterface $time, string $seed): string
    {
        $ms = (int) $time->format('Uv');
        $timePart = '';

        for ($i = 0; $i < 10; $i++) {
            $timePart = self::ALPHABET[$ms % 32].$timePart;
            $ms = intdiv($ms, 32);
        }

        return $timePart.self::random($seed);
    }

    /** A fixed id for a demo thing that has no time of its own (payment type, VAT rate, cashier). */
    public static function fixed(string $seed): string
    {
        return '01K5D3M000'.self::random($seed);
    }

    /** 16 Crockford characters (80 bits) from a seed. */
    private static function random(string $seed): string
    {
        $bytes = substr(hash('sha256', $seed, true), 0, 10);
        $bits = '';

        foreach (str_split($bytes) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $out = '';

        foreach (str_split($bits, 5) as $chunk) {
            $out .= self::ALPHABET[bindec($chunk)];
        }

        return $out;
    }
}
