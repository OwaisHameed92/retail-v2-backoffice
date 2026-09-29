<?php

namespace App\Domain\TillHealth\Queries;

use App\Domain\TillHealth\Data\BranchHealthRow;
use App\Domain\TillHealth\Data\HealthPresenter;
use App\Domain\TillHealth\Data\TillHealthRow;
use App\Domain\TillHealth\Support\HealthThresholds;
use Carbon\CarbonImmutable;

/**
 * One business's till and shop health, worked out now from the source rows (module 2.7): the admin tenant and
 * licence pages and the tenant dashboard. A handful of indexed queries on that business only, so it is always
 * current (no wait for the next refresh) and never scans other businesses.
 */
final class CompanyHealth
{
    /**
     * @return array{branches: array<string, array<string, mixed>>, tills: array<string, array<string, mixed>>, thresholds: array<string, int|string>}
     */
    public static function for(string $companyId, CarbonImmutable $now, bool $admin = true): array
    {
        $thresholds = HealthThresholds::fromConfig();
        $health = HealthSources::evaluate([$companyId], $now, $thresholds);
        $branches = [];
        $tills = [];

        foreach ($health['branches'] as $row) {
            /** @var BranchHealthRow $row */
            $branches[$row->branchId] = HealthPresenter::branch($row);
        }

        foreach ($health['tills'] as $row) {
            /** @var TillHealthRow $row */
            $tills[$row->till->registerId] = HealthPresenter::till($row, $admin);
        }

        return ['branches' => $branches, 'tills' => $tills, 'thresholds' => $thresholds->toArray()];
    }
}
