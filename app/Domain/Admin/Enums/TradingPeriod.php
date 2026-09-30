<?php

namespace App\Domain\Admin\Enums;

use Carbon\CarbonImmutable;

/**
 * Date range presets of the admin trading dashboard (module 3.2, DASHBOARD.md §2.1), in trading days
 * (Europe/London dates). `custom` takes `from` / `to` from the request.
 */
enum TradingPeriod: string
{
    case Today = 'today';
    case Yesterday = 'yesterday';
    case Last7Days = 'last7Days';
    case Last30Days = 'last30Days';
    case ThisMonth = 'thisMonth';
    case LastMonth = 'lastMonth';
    case Custom = 'custom';

    public function label(): string
    {
        return match ($this) {
            self::Today => 'Today',
            self::Yesterday => 'Yesterday',
            self::Last7Days => 'Last 7 days',
            self::Last30Days => 'Last 30 days',
            self::ThisMonth => 'This month',
            self::LastMonth => 'Last month',
            self::Custom => 'Custom',
        };
    }

    /**
     * The first and last trading day of a preset, `$today` being today's trading day. Null for custom.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    public function days(CarbonImmutable $today): ?array
    {
        $today = $today->startOfDay();

        return match ($this) {
            self::Today => [$today, $today],
            self::Yesterday => [$today->subDay(), $today->subDay()],
            self::Last7Days => [$today->subDays(6), $today],
            self::Last30Days => [$today->subDays(29), $today],
            self::ThisMonth => [$today->startOfMonth(), $today],
            self::LastMonth => [$today->subMonthNoOverflow()->startOfMonth(), $today->subMonthNoOverflow()->endOfMonth()->startOfDay()],
            self::Custom => null,
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label()], self::cases());
    }
}
