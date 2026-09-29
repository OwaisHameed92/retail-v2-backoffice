<?php

namespace App\Domain\TillHealth\Queries;

use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillHealth\Data\HealthPresenter;
use App\Domain\TillHealth\Enums\SyncState;
use App\Domain\TillHealth\Enums\TillState;
use App\Domain\TillHealth\Models\TillHealth;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The admin Till health list (module 2.7): every monitored till of every business, from `till_health` (indexed,
 * refreshed every 5 minutes), joined to the business, shop and till names. Filters: `filter` (attention, offline,
 * oldVersion, sync, clockSkew), `state`, `company`; search on business, shop, till and PC name.
 */
final class TillHealthList
{
    public const FILTERS = ['attention', 'offline', 'oldVersion', 'sync', 'clockSkew'];

    /**
     * @return array{data: list<mixed>, meta: array{page: int, perPage: int, total: int, lastPage: int, search: string|null, sort: string|null, direction: string}}
     */
    public static function paginate(Request $request, ?string $filter, ?TillState $state, ?string $companyId): array
    {
        $table = TableQuery::from($request)
            ->searchable(['companies.name', 'branches.name', 'registers.name', 'till_health.device_name'])
            ->sortable(['company_name', 'last_seen_at', 'app_version', 'clock_skew_seconds', 'problem_count'])
            ->defaultSort('problem_count', 'desc');

        $query = TillHealth::withoutCompanyScope()
            ->join('companies', 'companies.id', '=', 'till_health.company_id')
            ->join('branches', 'branches.id', '=', 'till_health.branch_id')
            ->join('registers', 'registers.id', '=', 'till_health.register_id')
            ->select('till_health.*')
            ->addSelect([
                'companies.name as company_name', 'branches.name as branch_name', 'branches.code as branch_code',
                'registers.name as register_name', 'registers.code as register_code', 'registers.is_main_till as register_is_main',
            ])
            ->when($companyId !== null, fn (Builder $q) => $q->where('till_health.company_id', $companyId))
            ->when($state !== null, fn (Builder $q) => $q->where('till_health.state', $state?->value));

        self::filter($query, $filter);

        return $table->paginate($query, fn (TillHealth $row) => [
            'id' => $row->id,
            ...HealthPresenter::till($row),
            'company' => ['id' => $row->company_id, 'name' => (string) $row->getAttribute('company_name')],
            'branch' => ['id' => $row->branch_id, 'name' => (string) $row->getAttribute('branch_name'), 'code' => (string) $row->getAttribute('branch_code')],
            'register' => ['name' => (string) $row->getAttribute('register_name'), 'code' => (string) $row->getAttribute('register_code'), 'isMainTill' => (bool) $row->getAttribute('register_is_main')],
            'checkedAt' => $row->checked_at->toIso8601ZuluString(),
        ]);
    }

    /**
     * @param  Builder<TillHealth>  $query
     */
    private static function filter(Builder $query, ?string $filter): void
    {
        match ($filter) {
            'attention' => $query->where('till_health.problem_count', '>', 0),
            'offline' => $query->where('till_health.state', TillState::Offline->value),
            'oldVersion' => $query->where('till_health.app_outdated', true),
            'sync' => $query->whereIn('till_health.sync_state', [SyncState::Failing->value, SyncState::Stalled->value]),
            'clockSkew' => $query->where('till_health.clock_skewed', true),
            default => null,
        };
    }
}
