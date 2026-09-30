<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Shared\Support\Money;

/**
 * Headline figures and the small sums reports share, exact with bcmath (never floats).
 */
final class Figures
{
    /**
     * A headline figure. `previous` given (a compare window) = its change in % (one decimal; null when it was 0).
     *
     * @return array{key: string, label: string, value: string|int|null, type: string, previous: string|int|null, change: string|null, goodWhen: string, hint: string|null}
     */
    public static function of(string $key, string $label, string|int|null $value, string $type = 'money', string|int|null $previous = null, bool $compared = false, string $goodWhen = 'up', ?string $hint = null): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'value' => $value,
            'type' => $type,
            'previous' => $compared ? $previous : null,
            'change' => $compared ? self::change($value, $previous) : null,
            'goodWhen' => $goodWhen,
            'hint' => $hint,
        ];
    }

    /** (now − before) ÷ before × 100, one decimal; null without a base. */
    public static function change(string|int|null $now, string|int|null $before): ?string
    {
        if ($now === null || $before === null || Money::isZero($before)) {
            return null;
        }

        return Money::round(bcdiv(bcmul(bcsub(Money::parse($now), Money::parse($before), 12), '100', 12), Money::parse($before), 12), 1);
    }

    /** part ÷ whole × 100, one decimal; null when the whole is 0. */
    public static function percent(string|int $part, string|int $whole): ?string
    {
        return Money::isZero($whole) ? null : Money::round(bcdiv(bcmul(Money::parse($part), '100', 12), Money::parse($whole), 12), 1);
    }

    /** Gross profit = net − cost; null when nothing had a cost (DASHBOARD.md §1.2: hide, never show 100 %). */
    public static function profit(string $net, string $cost): ?string
    {
        return Money::isZero($cost) ? null : Money::sub($net, $cost);
    }

    public static function margin(string $net, string $cost): ?string
    {
        $profit = self::profit($net, $cost);

        return $profit === null ? null : self::percent($profit, $net);
    }

    /** Average of an amount over a count, 2 dp; null for none. */
    public static function average(string $amount, int $count): ?string
    {
        return $count > 0 ? Money::round(bcdiv(Money::parse($amount), (string) $count, 12)) : null;
    }
}
