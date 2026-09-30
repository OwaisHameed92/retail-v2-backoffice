<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Build\ReportRowBuilder;
use App\Domain\Reporting\ReportTables;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds every `rpt_*` row of one shop's trading days from the raw till rows (DASHBOARD.md §4.8): delete the
 * days' rows, insert what the raw rows say now, in one transaction. Idempotent by construction — running it twice,
 * or after a replayed, re-ordered or late push, gives the same rows. Callers pass a handful of days at a time
 * (`reporting.days_per_rebuild`).
 */
final class RebuildReportDays
{
    private const INSERT_CHUNK = 500;

    /**
     * @param  list<string>  $days  trading days "Y-m-d"
     * @return int rows written
     */
    public function handle(string $companyId, string $branchId, array $days): int
    {
        $days = array_values(array_unique($days));

        if ($days === []) {
            return 0;
        }

        return DB::transaction(function () use ($companyId, $branchId, $days) {
            $tables = ReportRowBuilder::build($companyId, $branchId, $days, now('UTC')->format('Y-m-d H:i:s'));
            $written = 0;

            foreach (ReportTables::names() as $table) {
                DB::table($table)->where('company_id', $companyId)->where('branch_id', $branchId)->whereIn('trading_day', $days)->delete();

                foreach (array_chunk($tables[$table], self::INSERT_CHUNK) as $chunk) {
                    DB::table($table)->insert($chunk);
                    $written += count($chunk);
                }
            }

            return $written;
        }, 3);
    }
}
