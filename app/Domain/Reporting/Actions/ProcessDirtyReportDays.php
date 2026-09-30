<?php

namespace App\Domain\Reporting\Actions;

use App\Domain\Reporting\Support\DirtyDays;
use Illuminate\Support\Facades\DB;

/**
 * Rebuilds a business's dirty shop-days (DASHBOARD.md §4.8 step 4): takes the oldest dirty days, rebuilds them a
 * shop and a few days at a time, and clears only the markers it read, so a day marked again meanwhile stays queued.
 * Repeats until nothing is left or the pass limit is reached (the sweep picks up the rest).
 */
final class ProcessDirtyReportDays
{
    public function __construct(private readonly RebuildReportDays $rebuild) {}

    /**
     * @return int shop-days rebuilt
     */
    public function handle(string $companyId): int
    {
        $batch = max(1, (int) config('reporting.dirty_batch', 500));
        $passes = max(1, (int) config('reporting.dirty_passes', 20));
        $perRebuild = max(1, (int) config('reporting.days_per_rebuild', 7));
        $done = 0;

        for ($pass = 0; $pass < $passes; $pass++) {
            $dirty = DirtyDays::take($companyId, $batch);

            if ($dirty === []) {
                break;
            }

            $byBranch = [];

            foreach ($dirty as $row) {
                $byBranch[(string) $row->branch_id][] = ['day' => substr((string) $row->trading_day, 0, 10), 'token' => (string) $row->token];
            }

            foreach ($byBranch as $branchId => $days) {
                foreach (array_chunk($days, $perRebuild) as $chunk) {
                    DB::transaction(function () use ($companyId, $branchId, $chunk) {
                        $this->rebuild->handle($companyId, (string) $branchId, array_column($chunk, 'day'));
                        DirtyDays::clear($companyId, array_column($chunk, 'token'));
                    }, 3);
                    $done += count($chunk);
                }
            }
        }

        return $done;
    }
}
