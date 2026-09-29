<?php

namespace App\Domain\TillHealth\Queries;

use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\TillHealth\Data\BranchHealthRow;
use App\Domain\TillHealth\Data\SyncSource;
use App\Domain\TillHealth\Data\TillHealthRow;
use App\Domain\TillHealth\Data\TillSource;
use App\Domain\TillHealth\Support\HealthEvaluator;
use App\Domain\TillHealth\Support\HealthThresholds;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Loads and evaluates the health of a set of businesses in four queries whatever their size (module 2.7): active
 * shops of businesses that are not cancelled, their active tills with the live licence, their
 * `sync_branch_status` rows and the backlog kept at the last refresh. Admin/system code: reads across companies
 * with the query builder, always filtered to the given company ids.
 *
 * @phpstan-import-type Previous from HealthEvaluator
 */
final class HealthSources
{
    /**
     * @param  list<string>  $companyIds
     * @return array{branches: list<BranchHealthRow>, tills: list<TillHealthRow>}
     */
    public static function evaluate(array $companyIds, CarbonImmutable $now, ?HealthThresholds $thresholds = null): array
    {
        if ($companyIds === []) {
            return ['branches' => [], 'tills' => []];
        }

        $evaluator = new HealthEvaluator($thresholds ?? HealthThresholds::fromConfig(), $now);
        $branches = self::branches($companyIds);
        $tills = self::tills($companyIds);
        $syncs = self::syncs($companyIds);
        $previous = self::previous($companyIds);
        $out = ['branches' => [], 'tills' => []];

        foreach ($branches as $branchId => $companyId) {
            $result = $evaluator->branch($companyId, $branchId, $tills[$branchId] ?? [], $syncs[$branchId] ?? null, $previous);
            $out['branches'][] = $result['branch'];
            array_push($out['tills'], ...$result['tills']);
        }

        return $out;
    }

    /**
     * Active shops of live businesses: branch id → company id.
     *
     * @param  list<string>  $companyIds
     * @return array<string, string>
     */
    private static function branches(array $companyIds): array
    {
        return DB::table('branches')
            ->join('companies', 'companies.id', '=', 'branches.company_id')
            ->whereIn('branches.company_id', $companyIds)
            ->where('branches.is_active', true)
            ->whereNull('branches.deleted_at')
            ->whereNull('companies.deleted_at')
            ->where('companies.status', '!=', CompanyStatus::Cancelled->value)
            ->orderBy('branches.id')
            ->pluck('branches.company_id', 'branches.id')
            ->map(fn ($id) => (string) $id)
            ->all();
    }

    /**
     * Active tills with their live licence, grouped by shop.
     *
     * @param  list<string>  $companyIds
     * @return array<string, list<TillSource>>
     */
    private static function tills(array $companyIds): array
    {
        $rows = DB::table('registers')
            ->join('companies', 'companies.id', '=', 'registers.company_id')
            ->leftJoin('licences', function ($join) {
                $join->on('licences.live_register_id', '=', 'registers.id')->whereNull('licences.deleted_at');
            })
            ->whereIn('registers.company_id', $companyIds)
            ->where('registers.is_active', true)
            ->whereNull('registers.deleted_at')
            ->orderBy('registers.code')
            ->get([
                'registers.id as register_id', 'registers.company_id', 'registers.branch_id', 'registers.is_main_till',
                'companies.status as company_status',
                'licences.id as licence_id', 'licences.status as licence_status', 'licences.device_id', 'licences.device_name',
                'licences.last_check_in_at', 'licences.last_validated_at', 'licences.last_app_version', 'licences.last_contract_version',
                'licences.till_clock_skew_seconds', 'licences.diagnostics', 'licences.diagnostics_at', 'licences.lock_locked', 'licences.lock_reason',
            ]);

        $out = [];

        foreach ($rows as $row) {
            $out[(string) $row->branch_id][] = TillSource::fromRow($row);
        }

        return $out;
    }

    /**
     * @param  list<string>  $companyIds
     * @return array<string, SyncSource>
     */
    private static function syncs(array $companyIds): array
    {
        return DB::table('sync_branch_status')->whereIn('company_id', $companyIds)->get()
            ->mapWithKeys(fn (object $row) => [(string) $row->branch_id => SyncSource::fromRow($row)])
            ->all();
    }

    /**
     * @param  list<string>  $companyIds
     * @return array<string, Previous>
     */
    private static function previous(array $companyIds): array
    {
        return DB::table('till_health')->whereIn('company_id', $companyIds)
            ->get(['register_id', 'pending_sync_rows', 'pending_sync_rows_previous', 'diagnostics_at'])
            ->mapWithKeys(fn (object $row) => [(string) $row->register_id => [
                'pending' => $row->pending_sync_rows === null ? null : (int) $row->pending_sync_rows,
                'previous' => $row->pending_sync_rows_previous === null ? null : (int) $row->pending_sync_rows_previous,
                'diagnosticsAt' => TillSource::date($row->diagnostics_at),
            ]])
            ->all();
    }
}
