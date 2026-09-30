<?php

namespace App\Domain\StaffTime\Queries;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\Shared\Support\Money;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\StaffTime\Data\WorkedShift;
use App\Domain\StaffTime\Support\HoursMath;
use App\Domain\StaffTime\Support\TimeLookup;
use App\Domain\TillData\Models\TimesheetApproval;
use App\Domain\TillData\Models\WageRate;
use Carbon\CarbonImmutable;

/**
 * Timesheets (module 5.6): one row per person, shop and week (or the whole period) with hours worked, breaks,
 * rounded (paid) hours, overtime, rota hours planned, the till's own approval of the week and a wage estimate at the
 * person's hourly rate (User.ratePerHour; overtime at the same rate, the till has no overtime premium).
 * Only complete shifts count; missing clock-outs are counted so a manager fixes them on the till first.
 */
final class Timesheets
{
    /**
     * @return array<string, mixed>
     */
    public static function for(TimeFilters $filters, int $page, int $perPage, ?CarbonImmutable $now = null): array
    {
        $rows = self::rows($filters, $now);
        $wages = array_values(array_filter(array_column($rows, 'wage'), fn ($w) => $w !== null));

        return [
            'timesheets' => TimeLookup::page($rows, $page, $perPage),
            'summary' => [
                'people' => count(array_unique(array_column($rows, 'personId'))),
                'workedMinutes' => array_sum(array_column($rows, 'workedMinutes')),
                'paidMinutes' => array_sum(array_column($rows, 'paidMinutes')),
                'overtimeMinutes' => array_sum(array_column($rows, 'overtimeMinutes')),
                'plannedMinutes' => array_sum(array_column($rows, 'plannedMinutes')),
                'missing' => array_sum(array_column($rows, 'missing')),
                'wages' => Money::sum($wages),
                'withoutRate' => count(array_unique(array_column(array_filter($rows, fn (array $r) => $r['rate'] === null && $r['paidMinutes'] > 0), 'personId'))),
            ],
            'wageBands' => self::wageBands($filters),
        ];
    }

    /**
     * Every row, sorted by week, person and shop (the screen pages them; the payroll CSV writes them all).
     *
     * @return list<array<string, mixed>>
     */
    public static function rows(TimeFilters $filters, ?CarbonImmutable $now = null): array
    {
        $weekly = $filters->group === 'week';
        $rows = [];
        $blank = fn (string $person, ?string $shop, ?string $week) => [
            'personId' => $person, 'shopId' => $shop, 'weekStart' => $week, 'shifts' => 0, 'workedMinutes' => 0, 'breakMinutes' => 0,
            'paidMinutes' => 0, 'overtimeMinutes' => 0, 'plannedMinutes' => 0, 'missing' => 0,
        ];

        foreach (TimeSource::shifts($filters, $now) as $shift) {
            if (! $filters->includes($shift->day())) {
                continue;
            }
            $week = $weekly ? $shift->weekStart() : null;
            $key = $shift->userId.'|'.$shift->branchId.'|'.$week;
            $rows[$key] ??= $blank($shift->userId, $shift->branchId, $week);

            if ($shift->isComplete()) {
                $rows[$key]['shifts']++;
                $rows[$key]['workedMinutes'] += $shift->workedMinutes();
                $rows[$key]['breakMinutes'] += $shift->breakMinutes();
                $rows[$key]['paidMinutes'] += $shift->paidMinutes;
                $rows[$key]['overtimeMinutes'] += $shift->overtimeMinutes;
            } elseif ($shift->status !== WorkedShift::OPEN) {
                $rows[$key]['missing']++;
            }
        }

        foreach (TimeSource::rota($filters, $filters->from, $filters->to) as $r) {
            $week = $weekly ? CarbonImmutable::parse($r['day'], 'UTC')->startOfWeek(CarbonImmutable::MONDAY)->toDateString() : null;
            $key = $r['userId'].'|'.$r['branchId'].'|'.$week;
            $rows[$key] ??= $blank($r['userId'], $r['branchId'], $week);
            $rows[$key]['plannedMinutes'] += $r['plannedMinutes'];
        }

        return self::decorate(array_values($rows), $filters);
    }

    /**
     * Names, rate, wage estimate and the till's approval of the week.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private static function decorate(array $rows, TimeFilters $filters): array
    {
        $people = CashLookup::staff(array_column($rows, 'personId'));
        $shops = CashLookup::shops(array_column($rows, 'shopId'));
        $pay = TimeLookup::pay(array_column($rows, 'personId'));
        $approvals = $filters->group === 'week' ? self::approvals($filters) : [];
        $approvers = CashLookup::staff(array_map(fn (TimesheetApproval $a) => $a->approved_by_user_id, $approvals));

        $rows = array_map(function (array $row) use ($people, $shops, $pay, $approvals, $approvers) {
            $rate = $pay[$row['personId']]['rate'] ?? null;
            $approval = $approvals[$row['personId'].'|'.$row['shopId'].'|'.$row['weekStart']] ?? null;

            return [
                ...$row,
                'id' => implode('-', array_filter([$row['personId'], $row['shopId'], $row['weekStart']])),
                'person' => $people[$row['personId']] ?? 'Unknown',
                'shop' => CashLookup::name($shops, $row['shopId']),
                'differenceMinutes' => $row['paidMinutes'] - $row['plannedMinutes'],
                'rate' => $rate,
                'wage' => HoursMath::wage($row['paidMinutes'], $rate),
                'approval' => $approval === null ? null : [
                    'approvedAt' => CashLookup::iso($approval->approved_at),
                    'approvedBy' => CashLookup::name($approvers, $approval->approved_by_user_id),
                    'totalHours' => Money::normalise($approval->total_hours ?? '0'),
                    'overtimeHours' => Money::normalise($approval->overtime_hours ?? '0'),
                ],
            ];
        }, $rows);

        usort($rows, fn (array $a, array $b) => [$a['weekStart'] ?? '', mb_strtolower($a['person']), $a['shop'] ?? ''] <=> [$b['weekStart'] ?? '', mb_strtolower($b['person']), $b['shop'] ?? '']);

        return $rows;
    }

    /**
     * @return array<string, TimesheetApproval> keyed "person|shop|week"
     */
    private static function approvals(TimeFilters $filters): array
    {
        [$monday, $sunday] = $filters->weeks();

        return TimesheetApproval::query()
            ->when($filters->shop !== null, fn ($q) => $q->where('branch_id', $filters->shop))
            ->when($filters->person !== null, fn ($q) => $q->where('user_id', $filters->person))
            ->where('week_start', '>=', $monday)->where('week_start', '<=', $sunday)
            ->orderBy('approved_at')
            ->get(['id', 'user_id', 'branch_id', 'week_start', 'approved_by_user_id', 'approved_at', 'total_hours', 'overtime_hours'])
            ->mapWithKeys(fn (TimesheetApproval $a) => [$a->user_id.'|'.$a->branch_id.'|'.$a->week_start->toDateString() => $a])
            ->all();
    }

    /**
     * The tills' age-banded wage rates (minimum wage bands; a placeholder is the till's default, not the business's).
     *
     * @return list<array<string, mixed>>
     */
    private static function wageBands(TimeFilters $filters): array
    {
        return WageRate::query()
            ->when($filters->shop !== null, fn ($q) => $q->where(fn ($w) => $w->where('branch_id', $filters->shop)->orWhereNull('branch_id')))
            ->orderByDesc('effective_from')->orderBy('age_from')
            ->get(['id', 'label', 'age_from', 'age_to', 'effective_from', 'rate_per_hour', 'is_placeholder'])
            ->unique(fn (WageRate $w) => $w->label.'|'.$w->age_from.'|'.$w->effective_from->toDateString())
            ->take(12)
            ->map(fn (WageRate $w) => [
                'id' => (string) $w->id, 'label' => (string) $w->label, 'ageFrom' => $w->age_from, 'ageTo' => $w->age_to,
                'effectiveFrom' => $w->effective_from->toDateString(), 'rate' => CashLookup::money($w->rate_per_hour), 'isPlaceholder' => (bool) $w->is_placeholder,
            ])->values()->all();
    }
}
