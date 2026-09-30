<?php

namespace App\Domain\Cash\Queries;

use App\Domain\Cash\Data\CashFilters;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Support\TradingDay;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\DayLock;
use Illuminate\Support\Collection;

/**
 * Day lock status per shop and trading day (module 5.4), read only: `DayLock` is branch-owned (ownership.json), so
 * the portal never locks or unlocks a day. Each shop × day of the range (up to today, newest first) is `locked`
 * (a lock row is locked), `unlocked` (every row was unlocked again: reopened, with who, when and why), `open` (a
 * past day not locked yet) or `today` (still trading).
 */
final class DayLockBoard
{
    public const PER_PAGE = 50;

    /**
     * @return array<string, mixed>
     */
    public static function for(CashFilters $filters, int $page, int $perPage = self::PER_PAGE): array
    {
        $today = TradingDay::today()->format('Y-m-d');
        $to = min($filters->to, $today);
        $days = $filters->from <= $to ? array_reverse(TradingDay::range($filters->from, $to)) : [];
        $shops = Branch::query()->when($filters->shop !== null, fn ($q) => $q->whereKey($filters->shop))->orderBy('name')->get(['id', 'name']);
        $locks = $filters->onDays($filters->scope(DayLock::query(), false))->get()
            ->groupBy(fn (DayLock $l) => $l->branch_id.'|'.$l->trading_date->format('Y-m-d'));

        $rows = [];

        foreach ($days as $day) {
            foreach ($shops as $shop) {
                $rows[] = self::row($day, $shop, $locks->get($shop->id.'|'.$day), $today);
            }
        }

        $total = count($rows);
        $lastPage = max(1, (int) ceil($total / $perPage));
        $page = min(max(1, $page), $lastPage);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $staff = CashLookup::staff(array_merge(array_column($slice, 'lockedById'), array_column($slice, 'unlockedById')));
        $counts = array_count_values(array_column($rows, 'status'));

        return [
            'days' => [
                'data' => array_map(function (array $r) use ($staff) {
                    $r['lockedBy'] = CashLookup::name($staff, $r['lockedById']) ?? ($r['lockedById'] !== null && $r['lockedById'] !== '' ? $r['lockedById'] : null);
                    $r['unlockedBy'] = CashLookup::name($staff, $r['unlockedById']) ?? ($r['unlockedById'] !== null && $r['unlockedById'] !== '' ? $r['unlockedById'] : null);
                    unset($r['lockedById'], $r['unlockedById']);

                    return $r;
                }, $slice),
                'meta' => ['page' => $page, 'perPage' => $perPage, 'total' => $total, 'lastPage' => $lastPage, 'search' => null, 'sort' => null, 'direction' => 'desc'],
            ],
            'summary' => ['locked' => $counts['locked'] ?? 0, 'unlocked' => $counts['unlocked'] ?? 0, 'open' => $counts['open'] ?? 0],
        ];
    }

    /**
     * @param  Collection<int, DayLock>|null  $locks
     * @return array<string, mixed>
     */
    private static function row(string $day, Branch $shop, ?Collection $locks, string $today): array
    {
        $locked = $locks?->first(fn (DayLock $l) => $l->is_locked);
        $latest = $locks?->sortByDesc(fn (DayLock $l) => [(string) $l->getRawOriginal('unlocked_at'), (string) $l->getRawOriginal('locked_at')])->first();
        $shown = $locked ?? $latest;

        return [
            'key' => $shop->id.'|'.$day,
            'day' => $day,
            'shop' => (string) $shop->name,
            'status' => match (true) {
                $locked !== null => 'locked',
                $latest !== null => 'unlocked',
                $day === $today => 'today',
                default => 'open',
            },
            'lockedAt' => CashLookup::iso($shown?->locked_at),
            'lockedById' => $shown?->locked_by,
            'unlockedAt' => $locked === null ? CashLookup::iso($shown?->unlocked_at) : null,
            'unlockedById' => $locked === null ? $shown?->unlocked_by : null,
            'reason' => $locked === null && $shown !== null && $shown->unlock_reason !== '' ? $shown->unlock_reason : null,
        ];
    }
}
