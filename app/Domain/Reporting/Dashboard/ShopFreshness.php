<?php

namespace App\Domain\Reporting\Dashboard;

use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Models\RptSalesDaily;
use App\Domain\Reporting\ReportTables;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * "Updated N minutes ago" of the business dashboard (module 3.3, DASHBOARD.md §1.8), per shop, from our own clock:
 * the shop's last push (`sync_branch_status.last_push_at`) and last contact (hello, push or pull). State from the
 * last contact: live ≤ 2 min, recent ≤ 15 min, stale after (its recent days "may be incomplete"), never. Also when
 * the figures were last rebuilt, how many shop-days still wait, and whether the shops have any figures at all
 * (the "sync your till" empty state). Up to four small queries, read fresh (not cached).
 */
final class ShopFreshness
{
    public const LIVE_MINUTES = 2;

    public const RECENT_MINUTES = 15;

    /**
     * @return array{hasData: bool, lastPushAt: string|null, rebuiltAt: string|null, pendingDays: int, shops: list<array{id: string, name: string, lastPushAt: string|null, lastContactAt: string|null, state: string}>}
     */
    public static function for(ReportScope $scope, CarbonImmutable $now): array
    {
        $shops = Branch::query()
            ->leftJoin('sync_branch_status as s', fn ($j) => $j->on('s.branch_id', '=', 'branches.id')->on('s.company_id', '=', 'branches.company_id'))
            ->when($scope->branchIds !== null, fn ($q) => $q->withTrashed()->whereIn('branches.id', $scope->branchIds ?? []), fn ($q) => $q->where('branches.is_active', true))
            ->orderBy('branches.name')
            ->toBase()
            ->get(['branches.id', 'branches.name', 's.last_push_at', 's.last_hello_at', 's.last_pull_at'])
            ->map(function (object $row) use ($now) {
                $contact = collect([$row->last_push_at, $row->last_hello_at, $row->last_pull_at])->filter()->max();

                return [
                    'id' => (string) $row->id,
                    'name' => (string) $row->name,
                    'lastPushAt' => self::iso($row->last_push_at),
                    'lastContactAt' => self::iso($contact),
                    'state' => self::state(is_string($contact) ? CarbonImmutable::parse($contact, 'UTC') : null, $now),
                ];
            })->values()->all();

        $rebuilt = $scope->query(RptSalesDaily::class)->max(ReportTables::SALES_DAILY.'.rebuilt_at');
        $pending = DB::table(ReportTables::DIRTY_DAYS)->where('company_id', $scope->companyId)
            ->when($scope->branchIds !== null, fn (Builder $q) => $q->whereIn('branch_id', $scope->branchIds ?? []))
            ->whereBetween('trading_day', [$scope->from->toDateString(), $scope->to->toDateString()])
            ->count();
        $pushes = array_filter(array_column($shops, 'lastPushAt'));

        // Ever any figures for these shops (any day): tells "no sales yet, sync the till" from "a quiet period".
        $hasData = $rebuilt !== null || RptSalesDaily::query()
            ->when($scope->branchIds !== null, fn ($q) => $q->whereIn('branch_id', $scope->branchIds ?? []))
            ->exists();

        return [
            'hasData' => $hasData,
            'lastPushAt' => $pushes === [] ? null : max($pushes),
            'rebuiltAt' => self::iso($rebuilt),
            'pendingDays' => $pending,
            'shops' => $shops,
        ];
    }

    private static function state(?CarbonImmutable $contact, CarbonImmutable $now): string
    {
        if ($contact === null) {
            return 'never';
        }

        $minutes = $contact->diffInMinutes($now, false);

        return $minutes <= self::LIVE_MINUTES ? 'live' : ($minutes <= self::RECENT_MINUTES ? 'recent' : 'stale');
    }

    private static function iso(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? substr(str_replace(' ', 'T', $value), 0, 19).'Z' : null;
    }
}
