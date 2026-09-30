<?php

namespace App\Domain\StaffTime\Support;

use App\Domain\StaffTime\Data\TimeFilters;

/**
 * The payroll CSV of module 5.6: a short heading (business, shop, dates, rounding, overtime rule), then one line per
 * timesheet row. Hours are plain decimals (7.50 = 7 h 30 min), money plain pounds, so a payroll spreadsheet reads
 * them. Text a spreadsheet would run as a formula is quoted with '.
 */
final class PayrollCsv
{
    public const COLUMNS = [
        'Staff', 'Staff id', 'Shop', 'Week starting', 'Shifts', 'Worked hours', 'Paid hours (rounded)', 'Break hours',
        'Overtime hours', 'Rota hours', 'Hourly rate', 'Wage estimate', 'Missing clock-outs', 'Till approved hours',
        'Till approved overtime', 'Approved at',
    ];

    /**
     * @param  list<array<string, mixed>>  $rows  Timesheets::rows()
     * @return list<list<string>>
     */
    public static function lines(array $rows, TimeFilters $filters, string $business, string $shop): array
    {
        $lines = [
            ['Timesheets', self::text($business)],
            ['Shop', self::text($shop)],
            ['Dates', $filters->from.' to '.$filters->to],
            ['Rounding', $filters->rounding === 0 ? 'Exact minutes' : 'Nearest '.$filters->rounding.' minutes per shift'],
            ['Overtime', $filters->overtime === null ? 'None (no overtime rule chosen)' : 'After '.$filters->overtime.' hours a week'],
            ['Note', 'Wage estimate = paid hours x the hourly rate set on the till (overtime at the same rate). Shifts with a missing clock-out are not counted.'],
            [],
            self::COLUMNS,
        ];

        foreach ($rows as $r) {
            /** @var array{totalHours: string, overtimeHours: string, approvedAt: ?string}|null $approval */
            $approval = $r['approval'];
            $lines[] = [
                self::text((string) $r['person']), (string) $r['personId'], self::text((string) ($r['shop'] ?? '')),
                (string) ($r['weekStart'] ?? $filters->from), (string) $r['shifts'],
                HoursMath::hours((int) $r['workedMinutes']), HoursMath::hours((int) $r['paidMinutes']), HoursMath::hours((int) $r['breakMinutes']),
                HoursMath::hours((int) $r['overtimeMinutes']), HoursMath::hours((int) $r['plannedMinutes']),
                (string) ($r['rate'] ?? ''), (string) ($r['wage'] ?? ''), (string) $r['missing'],
                $approval['totalHours'] ?? '', $approval['overtimeHours'] ?? '', substr((string) ($approval['approvedAt'] ?? ''), 0, 10),
            ];
        }

        return $lines;
    }

    private static function text(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
