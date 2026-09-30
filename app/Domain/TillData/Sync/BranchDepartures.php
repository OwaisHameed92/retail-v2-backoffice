<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Registry\EntityDefinition;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * A shop's own hub-owned row (NewsTitle, BranchPrice: hub-owned, `branch_id` scope) moved to another shop
 * (ANSWERS-2026-09-29-b A.3). The shop it left gets a `D` for it, envelope `branchId` = the old shop and no payload,
 * which tills 0.1.8 (they skip a `U` naming another shop) and 0.1.9 (either works) both apply as "soft-delete my
 * copy". The new shop gets the row through the normal feed.
 *
 * `sync_branch_departures` keeps one row per (row, shop it left), stamped from the company's pull counter like any
 * feed row (HubVersions), so a shop that was offline through several moves still gets its `D`. A row that comes back
 * to a shop (or becomes company-wide again) clears that shop's departure: the normal feed sends it again. The shop
 * that made the move (a till push) gets nothing; it moved the row itself. A move from "every shop" (`branch_id`
 * blank) to one shop sends a `D` to every other shop, each with its OWN envelope `branchId` and no payload, never the
 * new shop's id, or the other tills would skip it and keep the every-shop row (ANSWERS-2026-09-30-portal point 2).
 */
final class BranchDepartures
{
    public const TABLE = 'sync_branch_departures';

    public static function applies(EntityDefinition $def): bool
    {
        return ! $def->tenancy && $def->isHubOwned() && $def->copy === null && ! $def->isKeyed() && $def->hasScopeColumn('branch_id');
    }

    /** A portal save through a hub-owned model (HubOwnedRow `saved`). */
    public static function fromModel(Model $model): void
    {
        $def = EntityRegistry::get((string) constant($model::class.'::TILL_ENTITY'));

        if (self::applies($def) && $model->wasChanged('branch_id')) {
            self::moved($def, (string) $model->getAttribute('company_id'), (string) $model->getKey(), $model->getOriginal('branch_id'), $model->getAttribute('branch_id'), null, now('UTC'));
        }
    }

    /**
     * Rows a till push wrote (EntityWriter): `$before` the stored rows (with `branch_id`), `$written` the new ones.
     *
     * @param  array<string, array<string, mixed>>  $before
     * @param  array<string, array<string, mixed>>  $written
     */
    public static function fromPush(EntityDefinition $def, SyncContext $context, array $before, array $written): void
    {
        if (! self::applies($def)) {
            return;
        }

        foreach ($written as $id => $row) {
            if (isset($before[$id]) && array_key_exists('branch_id', $row)) {
                self::moved($def, $context->companyId, (string) $id, $before[$id]['branch_id'] ?? null, $row['branch_id'], $context->branchId, $context->now);
            }
        }
    }

    public static function moved(EntityDefinition $def, string $companyId, string $id, mixed $from, mixed $to, ?string $movedBy, CarbonInterface|string $at): void
    {
        $from = (string) $from;
        $to = (string) $to;

        if ($from === $to) {
            return;
        }

        // Visible again where it now is: that shop gets the row itself, not a delete.
        DB::table(self::TABLE)->where('entity', $def->entity)->where('entity_id', $id)
            ->when($to !== '', fn ($query) => $query->where('branch_id', $to))
            ->delete();

        if ($to === '') {
            return;
        }

        $left = $from !== '' ? [$from] : DB::table('branches')->where('company_id', $companyId)->where('id', '<>', $to)->pluck('id')->all();
        $at = is_string($at) ? $at : $at->utc()->format('Y-m-d H:i:s');
        $rows = [];

        foreach ($left as $branchId) {
            if ($branchId !== $movedBy) {
                $rows[] = ['company_id' => $companyId, 'entity' => $def->entity, 'entity_id' => $id, 'branch_id' => (string) $branchId, 'from_branch_id' => $from, 'hub_version' => null, 'departed_at' => $at];
            }
        }

        if ($rows !== []) {
            DB::table(self::TABLE)->upsert($rows, ['entity', 'entity_id', 'branch_id'], ['company_id', 'from_branch_id', 'hub_version', 'departed_at']);
        }
    }

    public static function pending(string $companyId): bool
    {
        return DB::table(self::TABLE)->where('company_id', $companyId)->whereNull('hub_version')->exists();
    }

    /** Stamps the company's unstamped departures; call inside HubVersions' counter lock. Returns the last version. */
    public static function assign(string $companyId, int $version): int
    {
        $ids = DB::table(self::TABLE)->where('company_id', $companyId)->whereNull('hub_version')->orderBy('id')->pluck('id')->all();

        foreach ($ids as $id) {
            DB::table(self::TABLE)->where('id', $id)->whereNull('hub_version')->update(['hub_version' => ++$version]);
        }

        return $version;
    }
}
