<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Registry\FieldDefinition;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Applies one entity's changes of a chunk (seq order) with one read and bulk upserts:
 *
 * - upsert by id keeping the highest version (equal version: the later updatedAt); anything else is stale
 *   (accepted, no change) — contract §7 and §19.3 "never backwards"; keyed rows (§10.3) by the later updatedAt;
 * - a row id held by another company is rejected (never overwritten);
 * - hub-owned: content identical to the stored row (`hub_hash`) is an echo, acknowledged with no change (§19.2);
 *   not ordered by one till's row version against another's; a till change the portal or another shop has
 *   overtaken is not applied, a conflict is recorded; an applied one records the
 *   sending branch as `origin_branch_id` and clears `hub_version` for the pull (2.5) to stamp;
 * - historic rows (definitions `immutable`): once frozen only the allowed columns, deleted_at and the sync
 *   columns change; any other difference is recorded as a conflict;
 * - a delete without a payload soft-deletes the stored row (or is accepted with nothing to store).
 */
final class EntityWriter
{
    /** Columns that change even on a frozen historic row. */
    private const ALWAYS_MUTABLE = ['deleted_at', 'updated_at', 'row_version', 'synced_at', 'sync_seq', 'branch_id', 'register_id', 'portal_received_at'];

    /** @var list<string> ids written by the last write() */
    public array $written = [];

    public function __construct(private readonly SyncContext $context, private readonly ConflictRecorder $conflicts) {}

    /**
     * @param  list<MappedChange>  $changes  one entity, seq order
     * @return array<int, ChangeOutcome|Rejection> change index => outcome
     */
    public function write(EntityDefinition $def, array $changes): array
    {
        $state = $before = $this->existing($def, $changes);
        $outcomes = [];
        $pending = [];
        $tombstones = [];

        foreach ($changes as $mapped) {
            $change = $mapped->change;
            $id = $change->entityId;
            $current = $state[$id] ?? null;

            if ($current !== null && $current['company_id'] !== $this->context->companyId) {
                $outcomes[$change->index] = Rejection::for($change, 'entity.id_taken', "This {$def->entity} id is already used by another company.");

                continue;
            }

            $hash = $def->isHubOwned() && $mapped->row !== null ? RowHash::of($def, $mapped->row) : null;

            // Never echoed (§19.2): the content the portal already holds is acknowledged and changes nothing.
            if ($hash !== null && $current !== null && $hash === ($current['hub_hash'] ?? null)) {
                $outcomes[$change->index] = ChangeOutcome::Unchanged;

                continue;
            }

            if ($current !== null && ! $this->isNewer($def, $change->version, $mapped->row['updated_at'] ?? null, $current)) {
                $outcomes[$change->index] = ChangeOutcome::Stale;

                continue;
            }

            if ($mapped->row === null) {
                if ($current !== null) {
                    $tombstones[$id] = ['deleted_at' => $change->at, 'row_version' => $change->version, 'synced_at' => $this->context->now, 'sync_seq' => $change->seq ?: null, ...OwnershipRules::copyColumns($def->copy, $this->context->branchId)];
                    $state[$id] = [...$current, ...$tombstones[$id]];
                }
                $outcomes[$change->index] = ChangeOutcome::Applied;

                continue;
            }

            if ($current !== null && $def->isHubOwned() && $this->portalWins($def, $mapped, $current)) {
                $outcomes[$change->index] = ChangeOutcome::Conflict;

                continue;
            }

            $row = $mapped->row;

            if ($current !== null && $this->isFrozen($def, $current, $mapped)) {
                $row = $this->mergeFrozen($def, $current, $row, $mapped);
            }

            // First arrival at the portal is kept (v1.4 "received by the portal"); synced_at is the latest.
            $row['portal_received_at'] = $current['portal_received_at'] ?? $this->context->now;

            if ($def->isHubOwned()) {
                // Pull bookkeeping (2.5): new content from this shop, to be stamped with the next pull version
                // and sent to every other branch, never back to this one.
                $row['hub_version'] = null;
                $row['hub_hash'] = RowHash::of($def, $row);
                $row['origin_branch_id'] = $this->context->branchId;
            }

            $row = [...$row, ...OwnershipRules::copyColumns($def->copy, $this->context->branchId)];
            $pending[$id] = $row;
            $state[$id] = [...$row, 'hub_edited_at' => $current['hub_edited_at'] ?? null];
            $outcomes[$change->index] = ChangeOutcome::Applied;
        }

        $this->upsert($def, array_values($pending));
        BranchDepartures::fromPush($def, $this->context, $before, $pending);

        foreach ($tombstones as $id => $values) {
            DB::table($def->table)->where('id', $id)->where('company_id', $this->context->companyId)->update($values);
        }

        $this->written = array_keys($pending);

        return $outcomes;
    }

    /**
     * Never backwards (§7, §19.3): a higher version wins. On an equal version (the same row changed at two shops
     * whose row versions happen to match) the later `updatedAt` wins; the same or no `updatedAt` changes nothing,
     * so a replay is always a no-op.
     *
     * A hub-owned row last written by the portal or by another shop is not ordered by row versions: each till
     * counts its own, so one shop's number says nothing about another's. portalWins() decides it by the hub
     * version (`baseVersion`) or by time, and records a conflict rather than dropping the change.
     *
     * @param  array<string, mixed>  $current
     */
    private function isNewer(EntityDefinition $def, int $version, ?string $updatedAt, array $current): bool
    {
        $stored = (int) $current['row_version'];

        // Keyed rows (§10.3): the version is the pushing branch's seq, not comparable across shops. The later
        // change wins, then the higher seq; a replay changes nothing.
        if ($def->isKeyed()) {
            $storedAt = $current['updated_at'] === null ? '' : substr((string) $current['updated_at'], 0, 19);

            return [(string) $updatedAt, $version] > [$storedAt, $stored];
        }

        if ($def->isHubOwned() && ($current['origin_branch_id'] ?? null) !== $this->context->branchId) {
            return true;
        }

        if ($version !== $stored) {
            return $version > $stored;
        }

        $storedAt = $current['updated_at'] === null ? null : substr((string) $current['updated_at'], 0, 19);

        return $updatedAt !== null && ($storedAt === null || $updatedAt > $storedAt);
    }

    /**
     * A till change to a hub-owned row changed meanwhile by the portal or another shop: the stored row is kept and
     * the till's is recorded as a conflict (§19.3). With `baseVersion`: below the portal's current version (unless
     * that version is this shop's own earlier change), or another shop's accepted edit not yet stamped with a
     * version (newer than any version this till has seen). Without it (tills before v1.4): the portal deleted the row
     * (whenever the till's change was made), or the portal or another shop changed it after the till's change.
     *
     * @param  array<string, mixed>  $current
     */
    private function portalWins(EntityDefinition $def, MappedChange $mapped, array $current): bool
    {
        $change = $mapped->change;
        $hubVersion = $current['hub_version'] === null ? null : (int) $current['hub_version'];
        $origin = $current['origin_branch_id'] ?? null;
        $otherShop = $origin !== null && $origin !== $this->context->branchId;
        $local = (int) $current['row_version'];

        if ($change->baseVersion !== null) {
            if ($origin === $this->context->branchId || ($hubVersion !== null && $change->baseVersion >= $hubVersion)) {
                return false;
            }

            if ($hubVersion === null && ! $otherShop) {
                return false;
            }

            $hubVersion === null
                ? $this->conflicts->add($mapped, ConflictKind::BranchEditNewer, $local, "Another shop changed this {$def->entity} after the version the till edited ({$change->baseVersion}). The stored version was kept.")
                : $this->conflicts->add($mapped, ConflictKind::HubVersionNewer, $local, "The portal changed this {$def->entity} (version {$hubVersion}) after the version the till edited ({$change->baseVersion}). The portal's version was kept.");

            return true;
        }

        $hubEditedAt = $current['hub_edited_at'];

        // §19.3/§19.4 #7: a row the portal deleted stays deleted; a till change that would bring it back is a conflict.
        if ($origin === null && $current['deleted_at'] !== null && ($mapped->row['deleted_at'] ?? null) === null) {
            $this->conflicts->add($mapped, ConflictKind::HubEditNewer, $local, "The portal deleted this {$def->entity} at {$current['deleted_at']} UTC. It stays deleted.");

            return true;
        }

        if ($hubEditedAt !== null && substr((string) $hubEditedAt, 0, 19) > $change->at) {
            $this->conflicts->add($mapped, ConflictKind::HubEditNewer, $local, "The portal edited this {$def->entity} at {$hubEditedAt} UTC, after the till's change at {$change->at} UTC. The portal's version was kept.");

            return true;
        }

        // Both shops' own "last changed" times (the row's updatedAt, else the change time).
        $storedAt = $current['updated_at'] === null ? null : substr((string) $current['updated_at'], 0, 19);
        $incomingAt = (string) ($mapped->row['updated_at'] ?? $change->at);

        if ($otherShop && $storedAt !== null && $storedAt > $incomingAt) {
            $this->conflicts->add($mapped, ConflictKind::BranchEditNewer, $local, "Another shop changed this {$def->entity} at {$storedAt} UTC, after this till's change at {$incomingAt} UTC. The stored version was kept.");

            return true;
        }

        return false;
    }

    /**
     * Stored rows for the chunk's ids, across all companies (an id clash must be seen), locked for the transaction
     * where the database supports it. Historic entities load whole rows for the frozen merge.
     *
     * @param  list<MappedChange>  $changes
     * @return array<string, array<string, mixed>>
     */
    private function existing(EntityDefinition $def, array $changes): array
    {
        $ids = array_values(array_unique(array_map(fn (MappedChange $m) => $m->change->entityId, $changes)));
        $columns = ['id', 'company_id', 'row_version', 'updated_at', 'portal_received_at'];

        if ($def->isHubOwned()) {
            array_push($columns, 'hub_edited_at', 'deleted_at', ...EntityDefinition::HUB_COLUMNS, ...(BranchDepartures::applies($def) ? ['branch_id'] : []));
        }

        $columns = $def->immutable !== null ? ['*'] : $columns;
        $existing = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table($def->table)->whereIn('id', $chunk)->lockForUpdate()->get($columns) as $record) {
                $existing[$record->id] = (array) $record;
            }
        }

        return $existing;
    }

    /**
     * @param  array<string, mixed>  $current
     */
    private function isFrozen(EntityDefinition $def, array $current, MappedChange $mapped): bool
    {
        $rule = $def->immutable;

        return match (true) {
            $rule === null => false,
            $rule['always'] => true,
            $rule['when'] !== null && ParentResolver::matches($current, $rule['when']) => true,
            $rule['whenParent'] !== null => $mapped->parentFrozen,
            default => false,
        };
    }

    /**
     * Keep the stored value of every column the till may not change on a historic row; record what it tried.
     *
     * @param  array<string, mixed>  $current
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function mergeFrozen(EntityDefinition $def, array $current, array $incoming, MappedChange $mapped): array
    {
        $mutable = [...self::ALWAYS_MUTABLE, ...($def->immutable['mutable'] ?? [])];
        $changed = [];
        $merged = $incoming;

        foreach ($incoming as $column => $value) {
            if (in_array($column, $mutable, true) || in_array($column, ['id', 'company_id'], true)) {
                continue;
            }

            $merged[$column] = $current[$column] ?? null;

            $field = $column === 'extra' ? new FieldDefinition('extra', 'extra', 'json', true, null) : PayloadMapper::fieldOf($def, $column);

            if (! Values::same($field, $current[$column] ?? null, $value)) {
                $changed[] = $column;
            }
        }

        if ($changed !== []) {
            $allowed = $def->immutable['mutable'] ?? [];
            $applied = $allowed === [] ? 'the delete flag' : implode(', ', $allowed).' and the delete flag';
            $this->conflicts->add($mapped, ConflictKind::ImmutableChange, (int) $current['row_version'], "Historic {$def->entity}: the till changed ".implode(', ', $changed).". Kept the stored values; applied only {$applied}.");
        }

        return $merged;
    }

    /**
     * @param  list<array<string, mixed>>  $rows  every row has every column of the entity, in $def->columns order
     */
    private function upsert(EntityDefinition $def, array $rows): void
    {
        // Security review L4: an upsert never moves a row to another company. Should another company have taken an
        // id between existing() and here, the chunk is rolled back instead of overwriting its row.
        BulkWriter::upsert($def->table, $def->columns, $rows, array_values(array_diff($def->columns, ['id', 'company_id'])));

        foreach (array_chunk(array_column($rows, 'id'), 500) as $ids) {
            if (DB::table($def->table)->whereIn('id', $ids)->where('company_id', '!=', $this->context->companyId)->exists()) {
                throw new RuntimeException("A {$def->entity} id in this push belongs to another company.");
            }
        }
    }
}
