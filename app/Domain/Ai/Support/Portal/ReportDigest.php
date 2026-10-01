<?php

namespace App\Domain\Ai\Support\Portal;

use App\Domain\Reporting\Actions\BuildReport;
use App\Domain\Reporting\Dashboard\BusinessDashboardFilters;
use App\Domain\Reporting\Reports\ReportGrouping;
use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Reporting\Reports\ReportOptions;
use App\Domain\Reporting\Reports\ReportResult;
use App\Domain\Reporting\Reports\ReportTable;

/**
 * A report of module 4.8 (BuildReport, the same builder as the report page) cut down for the model: the headline
 * figures with their change, and the first rows of each table keyed by column label. Money is "£1234.50", percent
 * "12.5%", so the model never mixes up pounds, counts and quantities.
 */
final class ReportDigest
{
    public const ROWS = 15;

    /**
     * @param  list<string>|null  $tables  only these table keys (null = every table)
     * @return array<string, mixed>
     */
    public static function run(ReportKind $kind, BusinessDashboardFilters $window, string $view = '', ?array $tables = null, int $rows = self::ROWS): array
    {
        $options = new ReportOptions($window, self::grouping($window), $view);

        return self::compact(app(BuildReport::class)->handle($kind, $options), $tables, $rows);
    }

    /**
     * @param  list<string>|null  $only
     * @return array<string, mixed>
     */
    public static function compact(ReportResult $result, ?array $only = null, int $rows = self::ROWS): array
    {
        if (! $result->available) {
            return ['available' => false, 'notes' => $result->notes];
        }

        $summary = [];

        foreach ($result->summary as $figure) {
            $summary[] = array_filter([
                'figure' => $figure['label'],
                'value' => self::value($figure['value'], $figure['type']),
                'before' => $figure['previous'] === null ? null : self::value($figure['previous'], $figure['type']),
                'changePercent' => $figure['change'],
            ], fn (mixed $v) => $v !== null);
        }

        $tables = [];

        foreach ($result->tables as $table) {
            if ($only === null || in_array($table->key, $only, true)) {
                $tables[] = self::table($table, $rows);
            }
        }

        return array_filter(['summary' => $summary, 'tables' => $tables, 'notes' => $result->notes], fn (array $v) => $v !== []);
    }

    /**
     * @return array<string, mixed>
     */
    private static function table(ReportTable $table, int $limit): array
    {
        $map = fn (array $row) => array_reduce($table->columns, function (array $out, array $col) use ($row) {
            $out[$col['label']] = self::value($row[$col['key']] ?? null, $col['type']);

            return $out;
        }, []);

        $shown = array_slice($table->rows, 0, $limit);
        $total = $table->pagination['total'] ?? count($table->rows);

        return array_filter([
            'table' => $table->title,
            'rows' => array_map($map, $shown),
            'totals' => $table->totals === null ? null : $map($table->totals),
            'moreRowsNotShown' => max(0, $total - count($shown)) ?: null,
            'empty' => $shown === [] ? $table->empty : null,
        ], fn (mixed $v) => $v !== null);
    }

    private static function value(mixed $value, string $type): string|int|bool|null
    {
        if ($value === null || $value === '') {
            return null;
        }

        return match ($type) {
            'money', 'signedMoney' => '£'.$value,
            'percent' => $value.'%',
            default => is_scalar($value) ? $value : null,
        };
    }

    /** By day up to a month, by week up to ~4 months, else by month (keeps tables short). */
    private static function grouping(BusinessDashboardFilters $window): ReportGrouping
    {
        $days = (int) $window->from->diffInDays($window->to) + 1;

        return match (true) {
            $days <= 31 => ReportGrouping::Day,
            $days <= 120 => ReportGrouping::Week,
            default => ReportGrouping::Month,
        };
    }
}
