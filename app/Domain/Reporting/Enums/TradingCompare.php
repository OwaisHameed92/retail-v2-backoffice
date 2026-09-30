<?php

namespace App\Domain\Reporting\Enums;

use App\Domain\Reporting\Data\ReportScope;

/**
 * "Compare to" of the trading dashboards (admin 3.2, business 3.3; DASHBOARD.md §2.9). Default: the previous period.
 */
enum TradingCompare: string
{
    case PreviousPeriod = 'previousPeriod';
    case SameLastWeek = 'sameLastWeek';
    case SameLastYear = 'sameLastYear';
    case None = 'none';

    public function label(): string
    {
        return match ($this) {
            self::PreviousPeriod => 'Previous period',
            self::SameLastWeek => 'Same period last week',
            self::SameLastYear => 'Same period last year',
            self::None => 'No comparison',
        };
    }

    /** The compare window of a scope (§2.9); null for "No comparison". */
    public function window(ReportScope $scope): ?ReportScope
    {
        return match ($this) {
            self::PreviousPeriod => $scope->previousPeriod(),
            self::SameLastWeek => $scope->sameLastWeek(),
            self::SameLastYear => $scope->sameLastYear(),
            self::None => null,
        };
    }

    /** The delta's label: "vs previous period", "vs yesterday" (Today), "vs same day last week" (one day). */
    public function versus(bool $singleDay = false, bool $today = false): string
    {
        return match ($this) {
            self::PreviousPeriod => $today ? 'vs yesterday' : ($singleDay ? 'vs the day before' : 'vs previous period'),
            self::SameLastWeek => $singleDay ? 'vs same day last week' : 'vs same days last week',
            self::SameLastYear => 'vs last year',
            self::None => '',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $c) => ['value' => $c->value, 'label' => $c->label()], self::cases());
    }
}
