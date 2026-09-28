<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use Illuminate\Support\Facades\DB;

/**
 * Finishes child rows (SaleLine, SalePayment, SaleVat, CustomerOrderPayment, JournalLine, *Line…), which the
 * till sends with no branch or till of their own (contract §16):
 *
 * - branch_id / register_id come from the row itself when it has them, else from the parent (in this batch or
 *   already stored), else the sending branch and no till. A child that arrives before its parent is fixed up
 *   when the parent lands (ChunkApplier backfill).
 * - A parent of another company or another branch, or a parent rejected in the same batch, rejects the child.
 * - `parentFrozen`: whether the parent was historic (e.g. a completed sale) at the child's seq, for immutability.
 *
 * Must run before the chunk writes its own parents, so stored parents reflect every lower seq only.
 */
final class ParentResolver
{
    /** @var array<string, array<string, list<array{seq: int, row: array<string, mixed>}>>> entity => id => changes */
    private array $batch = [];

    /** @var array<string, array<string, true>> entity => ids rejected in this batch */
    private array $rejected = [];

    /**
     * @param  iterable<MappedChange>  $changes  every mapped change of the batch, in seq order
     * @param  iterable<Rejection>  $rejections  changes of the batch already rejected
     */
    public function __construct(iterable $changes, iterable $rejections = [])
    {
        foreach ($rejections as $rejection) {
            if ($rejection->entity !== null && $rejection->entityId !== null) {
                $this->rejected[$rejection->entity][$rejection->entityId] = true;
            }
        }

        foreach ($changes as $mapped) {
            if ($mapped->row !== null && $mapped->definition->children !== []) {
                $this->batch[$mapped->definition->entity][$mapped->change->entityId][] = [
                    'seq' => $mapped->change->seq,
                    'row' => $mapped->row,
                ];
            }
        }
    }

    /**
     * @param  list<MappedChange>  $children  child-scope changes of one chunk
     * @return array<int, Rejection> change index => rejection
     */
    public function resolve(array $children, SyncContext $context): array
    {
        $stored = $this->storedParents($children);
        $rejections = [];

        foreach ($children as $mapped) {
            $def = $mapped->definition;
            $row = $mapped->row;

            if ($row === null || $def->parent === null) {
                continue;
            }

            $parentEntity = $def->parent['entity'];

            if ($mapped->parentId !== null && isset($this->rejected[$parentEntity][$mapped->parentId])) {
                $rejections[$mapped->change->index] = Rejection::for($mapped->change, 'sync.parent_rejected', "The {$def->entity}'s {$parentEntity} was rejected in this batch.");

                continue;
            }

            $inBatch = $mapped->parentId === null ? [] : ($this->batch[$parentEntity][$mapped->parentId] ?? []);
            $fromDb = $mapped->parentId === null ? null : ($stored[$parentEntity][$mapped->parentId] ?? null);
            $latest = $inBatch === [] ? $fromDb : end($inBatch)['row'];

            if ($latest !== null && $latest['company_id'] !== $context->companyId) {
                $rejections[$mapped->change->index] = Rejection::for($mapped->change, 'sync.wrong_company', "The {$def->entity}'s {$parentEntity} belongs to another company.");

                continue;
            }

            if ($latest !== null && ($latest['branch_id'] ?? null) !== null && $latest['branch_id'] !== $context->branchId) {
                $rejections[$mapped->change->index] = Rejection::for($mapped->change, 'sync.wrong_branch', "The {$def->entity}'s {$parentEntity} belongs to another branch than the one sending it.");

                continue;
            }

            $row['branch_id'] ??= $latest['branch_id'] ?? $context->branchId;

            if ($def->hasScopeColumn('register_id')) {
                $row['register_id'] ??= $latest['register_id'] ?? null;
            }

            $condition = $def->immutable['whenParent'] ?? null;

            if ($condition !== null) {
                $asOf = $fromDb;

                foreach ($inBatch as $parentChange) {
                    if ($parentChange['seq'] < $mapped->change->seq) {
                        $asOf = $parentChange['row'];
                    }
                }

                $mapped->parentFrozen = $asOf !== null && self::matches($asOf, $condition);
            }

            $mapped->row = $row;
        }

        return $rejections;
    }

    /**
     * @param  array<string, mixed>  $row
     * @param  array<string, list<string>>  $condition  column => values
     */
    public static function matches(array $row, array $condition): bool
    {
        foreach ($condition as $column => $values) {
            if (! in_array((string) ($row[$column] ?? ''), $values, true)) {
                return false;
            }
        }

        return true;
    }

    /**
     * One query per parent table for the parents this chunk references.
     *
     * @param  list<MappedChange>  $children
     * @return array<string, array<string, array<string, mixed>>> entity => id => row
     */
    private function storedParents(array $children): array
    {
        $ids = [];
        $extraColumns = [];

        foreach ($children as $mapped) {
            if ($mapped->parentId !== null && $mapped->definition->parent !== null) {
                $entity = $mapped->definition->parent['entity'];
                $ids[$entity][$mapped->parentId] = true;
                $extraColumns[$entity] = array_merge($extraColumns[$entity] ?? [], array_keys($mapped->definition->immutable['whenParent'] ?? []));
            }
        }

        $stored = [];

        foreach ($ids as $entity => $parentIds) {
            $parent = EntityRegistry::get($entity);
            $columns = array_values(array_unique(array_merge(
                ['id', 'company_id'],
                array_intersect(['branch_id', 'register_id'], $parent->columns),
                $extraColumns[$entity],
            )));

            foreach (array_chunk(array_keys($parentIds), 500) as $chunk) {
                foreach (DB::table($parent->table)->whereIn('id', $chunk)->get($columns) as $record) {
                    $stored[$entity][$record->id] = (array) $record;
                }
            }
        }

        return $stored;
    }
}
