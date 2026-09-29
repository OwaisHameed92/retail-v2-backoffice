<?php

namespace App\Domain\Sync\Support;

use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Sync\BranchDepartures;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Reads one page of the pull feed for a branch (module 2.5, contract §8, §10, §19.2): the tables of
 * HubVersions::feed() only (hub-owned rows, relayed and drafted branch rows, portal edits of Company / Branch; any
 * other till-owned table is never read), `hub_version > since`, oldest first, filtered by PullVisibility (company-wide
 * or addressed to this branch, never a row whose current content this branch pushed), plus this branch's
 * departures (a shop row that moved to another shop: a `D` here).
 */
final class PullFeed
{
    /**
     * `limit` rows (ask for one more than the page to learn `hasMore`), each [entity, id, version, departure]:
     * `departure` is null for a feed row, or [the row's branch before it moved away from this branch ('' = every
     * shop), when] for a `D` to this branch (BranchDepartures, ANSWERS-2026-09-29-b A.3).
     *
     * @return list<array{0: string, 1: string, 2: int, 3: array{0: string, 1: string}|null}>
     */
    public function page(string $companyId, string $branchId, int $since, int $limit): array
    {
        $query = DB::table(BranchDepartures::TABLE)
            ->selectRaw('entity, entity_id as id, hub_version as version, from_branch_id as departed_from, departed_at')
            ->where('company_id', $companyId)
            ->where('branch_id', $branchId)
            ->where('hub_version', '>', $since);

        foreach (HubVersions::feed() as $def) {
            $query->unionAll($this->visible($def, $companyId, $branchId, $since));
        }

        return $query->orderBy('version')->limit($limit)->get()
            ->map(fn ($row) => [
                (string) $row->entity, (string) $row->id, (int) $row->version,
                $row->departed_at === null ? null : [(string) $row->departed_from, (string) $row->departed_at],
            ])
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
            foreach (DB::table($def->table)->where(HubVersions::companyColumn($def), $companyId)->whereIn('id', $chunk)->get() as $row) {
                $rows[(string) $row->id] = (array) $row;
            }
        }

        return $rows;
    }

    private function visible(EntityDefinition $def, string $companyId, string $branchId, int $since): Builder
    {
        $query = DB::table($def->table)
            ->selectRaw('? as entity, id, hub_version as version, NULL as departed_from, NULL as departed_at', [$def->entity])
            ->where(HubVersions::companyColumn($def), $companyId)
            ->where('hub_version', '>', $since);

        return PullVisibility::apply($query, $def, $companyId, $branchId);
    }
}
