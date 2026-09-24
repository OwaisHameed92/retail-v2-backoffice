<?php

namespace App\Domain\Billing\Support;

use App\Domain\Shared\Support\Money;

/**
 * Line and total arithmetic on decimal strings (bcmath via Money), never floats.
 *
 * - net = quantity × unit price, rounded to the penny (half away from zero)
 * - VAT = net × rate / 100, rounded per line; gross = net + VAT
 * - invoice subtotal / VAT / total = sums of the line values, so the lines always add up to the totals.
 */
final class InvoiceMaths
{
    private const WORK_SCALE = 12;

    /**
     * @return array{net: string, vat: string, gross: string}
     */
    public static function line(string $quantity, string $unitPrice, string $vatRate): array
    {
        $net = Money::mul(Money::normalise($quantity, Money::QUANTITY_SCALE), Money::normalise($unitPrice));
        $vat = self::vatOn($net, $vatRate);

        return ['net' => $net, 'vat' => $vat, 'gross' => Money::add($net, $vat)];
    }

    public static function vatOn(string $net, string $vatRate): string
    {
        if (Money::isZero($vatRate)) {
            return '0.00';
        }

        $vat = bcdiv(bcmul(Money::parse($net), Money::parse($vatRate), self::WORK_SCALE), '100', self::WORK_SCALE);

        return Money::round($vat);
    }

    /**
     * Split a VAT-inclusive amount (credit notes): net = gross × 100 / (100 + rate), VAT = gross − net.
     *
     * @return array{net: string, vat: string}
     */
    public static function splitGross(string $gross, string $vatRate): array
    {
        $gross = Money::normalise($gross);

        if (Money::isZero($vatRate)) {
            return ['net' => $gross, 'vat' => '0.00'];
        }

        $net = Money::round(bcdiv(bcmul(Money::parse($gross), '100', self::WORK_SCALE), bcadd('100', Money::parse($vatRate), self::WORK_SCALE), self::WORK_SCALE));

        return ['net' => $net, 'vat' => Money::sub($gross, $net)];
    }

    /**
     * @param  iterable<array{net: string, vat: string, gross: string}>  $lines
     * @return array{subtotal: string, vat_total: string, total: string}
     */
    public static function totals(iterable $lines): array
    {
        $net = [];
        $vat = [];
        $gross = [];

        foreach ($lines as $line) {
            $net[] = $line['net'];
            $vat[] = $line['vat'];
            $gross[] = $line['gross'];
        }

        return ['subtotal' => Money::sum($net), 'vat_total' => Money::sum($vat), 'total' => Money::sum($gross)];
    }

    /** a ÷ b to 4 decimal places (proration quantities). */
    public static function ratio(int $part, int $whole): string
    {
        return Money::round(bcdiv((string) $part, (string) max(1, $whole), self::WORK_SCALE), Money::QUANTITY_SCALE);
    }

    /** The smaller of two amounts. */
    public static function min(string $a, string $b): string
    {
        return Money::compare($a, $b) <= 0 ? Money::normalise($a) : Money::normalise($b);
    }
}
