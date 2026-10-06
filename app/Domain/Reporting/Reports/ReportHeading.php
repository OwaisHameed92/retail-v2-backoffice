<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;

/**
 * The heading of a printed or exported report: what it is, for whom, which shop and till, which days and which
 * compare window, and when it was made (shop time zone).
 */
final class ReportHeading
{
    /**
     * @param  array{branch: array{name: string}|null, till: array{label: string}|null}  $context
     * @return array<string, string>
     */
    public static function for(ReportKind $kind, ReportOptions $options, array $context, string $business, ?CarbonImmutable $now = null): array
    {
        $w = $options->window;
        $compare = $kind->compares() ? $options->compareScope() : null;

        return array_filter([
            'Report' => $kind->label().($kind->groups() ? ' (by '.$options->group->value.')' : ''),
            'Business' => $business,
            'Shop' => $context['branch']['name'] ?? 'All shops',
            'Till' => $context['till']['label'] ?? 'All tills',
            'Dates' => $kind->usesDates() ? self::range($w->from, $w->to) : 'Now (stock as last sent by the tills)',
            'Compared with' => $compare === null ? null : $w->compare->label().', '.self::range($compare->from, $compare->to),
            'Created' => ($now ?? CarbonImmutable::now())->setTimezone(Country::zone())->format('j M Y H:i'),
        ], fn (?string $v) => $v !== null);
    }

    public static function range(CarbonImmutable $from, CarbonImmutable $to): string
    {
        return $from->equalTo($to) ? $from->format('j M Y') : $from->format('j M Y').' – '.$to->format('j M Y');
    }

    /** "sales-summary-2026-09-01-to-2026-09-30.csv". */
    public static function filename(ReportKind $kind, ReportOptions $options): string
    {
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($kind->label())), '-');
        $days = $kind->usesDates()
            ? $options->window->from->toDateString().'-to-'.$options->window->to->toDateString()
            : CarbonImmutable::now()->setTimezone(Country::zone())->format('Y-m-d');

        return "{$slug}-{$days}.csv";
    }
}
