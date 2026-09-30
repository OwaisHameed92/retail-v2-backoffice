<?php

namespace App\Domain\Reporting\Reports;

use Carbon\CarbonImmutable;

/**
 * "By day / week / month" of a report's period table. Weeks run Monday to Sunday (DASHBOARD.md §1.3); a period cut by
 * the range holds only the range's days.
 */
enum ReportGrouping: string
{
    case Day = 'day';
    case Week = 'week';
    case Month = 'month';

    /** The bucket of a trading day "Y-m-d": the day, the week's Monday, or "Y-m". */
    public function key(string $day): string
    {
        $date = CarbonImmutable::parse($day, 'UTC');

        return match ($this) {
            self::Day => $date->toDateString(),
            self::Week => $date->startOfWeek(CarbonImmutable::MONDAY)->toDateString(),
            self::Month => $date->format('Y-m'),
        };
    }

    /** "Wed 23 Sept 2026", "w/c 21 Sept 2026", "September 2026". */
    public function label(string $key): string
    {
        return match ($this) {
            self::Day => CarbonImmutable::parse($key, 'UTC')->format('D j M Y'),
            self::Week => 'w/c '.CarbonImmutable::parse($key, 'UTC')->format('j M Y'),
            self::Month => CarbonImmutable::parse($key.'-01', 'UTC')->format('F Y'),
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return [['value' => 'day', 'label' => 'By day'], ['value' => 'week', 'label' => 'By week'], ['value' => 'month', 'label' => 'By month']];
    }
}
