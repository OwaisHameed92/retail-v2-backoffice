<?php

namespace App\Domain\StaffTime\Queries;

use App\Domain\Cash\Support\CashLookup;
use App\Domain\StaffTime\Data\TimeFilters;
use App\Domain\StaffTime\Data\WorkedShift;
use Carbon\CarbonImmutable;

/**
 * The rota week (module 5.6), read only: `RotaShift` is branch-owned (ownership.json), so rotas are made on the till
 * and pushed to us; the portal may not write them. One row per person with each day's planned shifts beside the
 * hours they actually worked (complete shifts, rounded as chosen) and the week's planned-versus-worked difference.
 */
final class RotaWeek
{
    /**
     * @return array<string, mixed>
     */
    public static function for(TimeFilters $filters, string $monday, ?CarbonImmutable $now = null): array
    {
        $week = $filters->withDays($monday, CarbonImmutable::parse($monday, 'UTC')->addDays(6)->toDateString());
        $days = array_map(fn (int $i) => CarbonImmutable::parse($monday, 'UTC')->addDays($i)->toDateString(), range(0, 6));
        $rota = TimeSource::rota($week, $week->from, $week->to);
        $shifts = array_values(array_filter(TimeSource::shifts($week, $now), fn (WorkedShift $s) => $week->includes($s->day())));

        $people = [];
        $cell = fn () => ['planned' => [], 'plannedMinutes' => 0, 'workedMinutes' => 0, 'missing' => 0, 'onShift' => false];
        $shopIds = array_merge(array_column($rota, 'branchId'), array_map(fn (WorkedShift $s) => $s->branchId, $shifts));
        $shops = CashLookup::shops($shopIds);
        $multiShop = count(array_unique(array_filter($shopIds))) > 1;

        foreach ($rota as $r) {
            $people[$r['userId']] ??= array_fill_keys($days, null);
            $people[$r['userId']][$r['day']] ??= $cell();
            $people[$r['userId']][$r['day']]['planned'][] = [
                'id' => $r['id'], 'start' => $r['start'], 'end' => $r['end'], 'breakMinutes' => $r['breakMinutes'],
                'minutes' => $r['plannedMinutes'], 'readable' => $r['readable'], 'isPublished' => $r['isPublished'], 'note' => $r['note'],
                'shop' => $multiShop ? CashLookup::name($shops, $r['branchId']) : null,
            ];
            $people[$r['userId']][$r['day']]['plannedMinutes'] += $r['plannedMinutes'];
        }

        foreach ($shifts as $s) {
            $people[$s->userId] ??= array_fill_keys($days, null);
            $people[$s->userId][$s->day()] ??= $cell();
            $people[$s->userId][$s->day()]['workedMinutes'] += $s->paidMinutes;
            $people[$s->userId][$s->day()]['missing'] += in_array($s->status, [WorkedShift::MISSING_OUT, WorkedShift::MISSING_IN], true) ? 1 : 0;
            $people[$s->userId][$s->day()]['onShift'] = $people[$s->userId][$s->day()]['onShift'] || $s->status === WorkedShift::OPEN;
        }

        $names = CashLookup::staff(array_keys($people));
        $rows = [];

        foreach ($people as $id => $cells) {
            $planned = array_sum(array_map(fn (?array $c) => $c['plannedMinutes'] ?? 0, $cells));
            $worked = array_sum(array_map(fn (?array $c) => $c['workedMinutes'] ?? 0, $cells));
            $rows[] = [
                'personId' => (string) $id, 'person' => $names[(string) $id] ?? 'Unknown', 'days' => $cells,
                'plannedMinutes' => $planned, 'workedMinutes' => $worked, 'differenceMinutes' => $worked - $planned,
            ];
        }

        usort($rows, fn (array $a, array $b) => mb_strtolower($a['person']) <=> mb_strtolower($b['person']));

        return [
            'week' => $monday,
            'days' => $days,
            'rows' => $rows,
            'summary' => [
                'people' => count($rows),
                'shifts' => count($rota),
                'plannedMinutes' => array_sum(array_column($rows, 'plannedMinutes')),
                'workedMinutes' => array_sum(array_column($rows, 'workedMinutes')),
                'drafts' => count(array_filter($rota, fn (array $r) => ! $r['isPublished'])),
                'unreadable' => count(array_filter($rota, fn (array $r) => ! $r['readable'])),
            ],
        ];
    }
}
