<?php

namespace App\Domain\Sync\Support;

use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads one page of the pull feed for a branch (module 2.5, contract §8, §10, §19.2): hub-owned rows only (a
 * till-owned table is never read), `hub_version > since`, oldest first. A row is visible to the branch when it is
 * company-wide or addressed to it (a hub-owned table with its own `branch_id`, e.g. NewsTitle), and never when that
 * branch pushed its current content (`origin_branch_id`).
 */
final class PullFeed
{
    /**
     * `limit` rows (ask for one more than the page to learn `hasMore`), each [entity, id, version].
     *
     * @return list<array{0: string, 1: string, 2: int}>
     */
    public function page(string $companyId, string $branchId, int $since, int $limit): array
    {
        $query = null;

        foreach (HubVersions::entities() as $def) {
            $part = $this->visible($def, $companyId, $branchId, $since);
            $query = $query === null ? $part : $query->unionAll($part);
        }

        if ($query === null) {
            return [];
        }

        return $query->orderBy('version')->limit($limit)->get()
            ->map(fn ($row) => [(string) $row->entity, (string) $row->id, (int) $row->version])
            ->all();
    }

    /**
     * Full rows of one entity, keyed by id.
     *
     * @param  list<string>  $ids
     * @return array<string, array<string, mixed>>
     */
    public function rows(EntityDefinition $def, string $companyId, array $ids): array
    {
        $rows = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($def->table)->where('company_id', $companyId)->whereIn('id', $chunk)->get() as $row) {
                $rows[(string) $row->id] = (array) $row;
            }
        }

        return $rows;
    }

    private function visible(EntityDefinition $def, string $companyId, string $branchId, int $since): Builder
    {
        return DB::table($def->table)
            ->selectRaw('? as entity, id, hub_version as version', [$def->entity])
            ->where('company_id', $companyId)
            ->where('hub_version', '>', $since)
            ->where(fn (Builder $q) => $q->whereNull('origin_branch_id')->orWhere('origin_branch_id', '<>', $branchId))
            ->when($def->hasScopeColumn('branch_id'), fn (Builder $q) => $q->where(
                fn (Builder $w) => $w->whereNull('branch_id')->orWhere('branch_id', '')->orWhere('branch_id', $branchId),
            ));
    }
}
