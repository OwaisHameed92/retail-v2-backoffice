<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Shared\Support\Money;

/**
 * Deltas for KPI cards and tiles, in the shape of the React `StatDelta`: `{value, direction, goodWhen, label}`.
 * Percentages are whole numbers worked out with bcmath; no delta when the previous figure is zero and this one is not.
 *
 * @phpstan-type Delta array{value: string, direction: 'up'|'down'|'flat', goodWhen: 'up'|'down', label: string}
 */
final class Change
{
    /**
     * @param  'up'|'down'  $goodWhen
     * @return Delta|null
     */
    public static function percent(string $current, string $previous, string $label, string $goodWhen = 'up'): ?array
    {
        if (Money::isZero($previous)) {
            return Money::isZero($current) ? ['value' => '0%', 'direction' => 'flat', 'goodWhen' => $goodWhen, 'label' => $label] : null;
        }

        $percent = Money::round(bcdiv(bcmul(bcsub(Money::parse($current), Money::parse($previous), 4), '100', 4), Money::parse($previous), 4), 0);

        return [
            'value' => ltrim($percent, '-').'%',
            'direction' => self::direction(bccomp($percent, '0', 0)),
            'goodWhen' => $goodWhen,
            'label' => $label,
        ];
    }

    /**
     * @param  'up'|'down'  $goodWhen
     * @return Delta
     */
    public static function count(int $current, int $previous, string $label, string $goodWhen = 'up'): array
    {
        return [
            'value' => (string) abs($current - $previous),
            'direction' => self::direction($current <=> $previous),
            'goodWhen' => $goodWhen,
            'label' => $label,
        ];
    }

    /**
     * @return 'up'|'down'|'flat'
     */
    private static function direction(int $sign): string
    {
        return $sign > 0 ? 'up' : ($sign < 0 ? 'down' : 'flat');
    }
}
