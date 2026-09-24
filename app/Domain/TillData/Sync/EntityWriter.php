<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Registry\FieldDefinition;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use Illuminate\Support\Facades\DB;

/**
 * Applies one entity's changes of a chunk (seq order) with one read and bulk upserts:
 *
 * - upsert by id keeping the highest version; a version not above the stored one is stale (accepted, no change);
 * - a row id held by another company is rejected (never overwritten);
 * - hub-owned: a till change older than the portal's last edit is not applied, a conflict is recorded;
 * - historic rows (definitions `immutable`): once frozen only the allowed columns, deleted_at and the sync
 *   columns change; any other difference is recorded as a conflict;
 * - a delete without a payload soft-deletes the stored row (or is accepted with nothing to store).
 */
final class EntityWriter
{
    /** Columns that change even on a frozen historic row. */
    private const ALWAYS_MUTABLE = ['deleted_at', 'updated_at', 'row_version', 'synced_at', 'sync_seq', 'branch_id', 'register_id'];

    /** @var list<string> ids written by the last write() */
    public array $written = [];

    public function __construct(private readonly SyncContext $context, private readonly ConflictRecorder $conflicts) {}

    /**
     * @param  list<MappedChange>  $changes  one entity, seq order
     * @return array<int, ChangeOutcome|Rejection> change index => outcome
     */
    public function write(EntityDefinition $def, array $changes): array
    {
        $state = $this->existing($def, $changes);
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

            if ($current !== null && $change->version <= (int) $current['row_version']) {
                $outcomes[$change->index] = ChangeOutcome::Stale;

                continue;
            }

            if ($mapped->row === null) {
                if ($current !== null) {
                    $tombstones[$id] = ['deleted_at' => $change->at, 'row_version' => $change->version, 'synced_at' => $this->context->now, 'sync_seq' => $change->seq ?: null];
                    $state[$id] = [...$current, ...$tombstones[$id]];
                }
                $outcomes[$change->index] = ChangeOutcome::Applied;

                continue;
            }

            $hubEditedAt = $current['hub_edited_at'] ?? null;

            if ($hubEditedAt !== null && substr((string) $hubEditedAt, 0, 19) > $change->at) {
                $this->conflicts->add($mapped, ConflictKind::HubEditNewer, (int) $current['row_version'], "The portal edited this {$def->entity} at {$hubEditedAt} UTC, after the till's change at {$change->at} UTC. The portal's version was kept.");
                $outcomes[$change->index] = ChangeOutcome::Conflict;

                continue;
            }

            $row = $mapped->row;

            if ($current !== null && $this->isFrozen($def, $current, $mapped)) {
                $row = $this->mergeFrozen($def, $current, $row, $mapped);
            }

            $pending[$id] = $row;
            $state[$id] = [...$row, 'hub_edited_at' => $hubEditedAt];
            $outcomes[$change->index] = ChangeOutcome::Applied;
        }

        $this->upsert($def, array_values($pending));

        foreach ($tombstones as $id => $values) {
            DB::table($def->table)->where('id', $id)->update($values);
        }

        $this->written = array_keys($pending);

        return $outcomes;
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
        $columns = $def->immutable !== null
            ? ['*']
            : ($def->isHubOwned() ? ['id', 'company_id', 'row_version', 'hub_edited_at'] : ['id', 'company_id', 'row_version']);
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
        BulkWriter::upsert($def->table, $def->columns, $rows, array_values(array_diff($def->columns, ['id'])));
    }
}
