<?php

namespace App\Domain\TillData\Sync;

use App\Domain\Reporting\Sync\ReportDayTracker;
use App\Domain\TillData\Actions\RecomputeCustomerBalances;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Registry\EntityDefinition;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Applies one chunk of mapped changes in one transaction: ledger dedupe, child scope resolution, tenancy rows,
 * one EntityWriter pass per entity (parents before children), child backfill, customer balances from the ledger,
 * reporting days (module 3.1: sale trading days stamped, touched shop-days marked dirty), ledger and conflict rows.
 *
 * If the database refuses the chunk (a constraint, a value MySQL will not take), the chunk is rolled back and
 * retried one change at a time, so only the failing change is rejected (`store.failed`) and the rest apply.
 */
final class ChunkApplier
{
    public function __construct(
        private readonly ChangeLedger $ledger,
        private readonly RecomputeCustomerBalances $balances,
        private readonly ReportDayTracker $reportDays,
    ) {}

    /**
     * @param  list<MappedChange>  $chunk  seq order
     * @return array<int, ChangeOutcome|Rejection> change index => outcome
     */
    public function apply(array $chunk, SyncContext $context, ParentResolver $parents): array
    {
        try {
            return DB::transaction(fn () => $this->applyInTransaction($chunk, $context, $parents));
        } catch (QueryException $e) {
            if (count($chunk) > 1) {
                $outcomes = [];

                foreach ($chunk as $mapped) {
                    $outcomes += $this->apply([$mapped], $context, $parents);
                }

                return $outcomes;
            }

            $change = $chunk[0]->change;
            // Never log the payload: it may hold personal data. The SQL error names the column.
            Log::error('Till sync could not store a change.', [
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'key' => $change->key,
                'seq' => $change->seq,
                'error' => $e->getPrevious()?->getMessage() ?? $e->getMessage(),
            ]);

            return [$change->index => Rejection::for($change, 'store.failed', "The {$change->entity} row could not be stored. Support has been alerted.")];
        }
    }

    /**
     * @param  list<MappedChange>  $chunk
     * @return array<int, ChangeOutcome|Rejection>
     */
    private function applyInTransaction(array $chunk, SyncContext $context, ParentResolver $parents): array
    {
        $conflicts = new ConflictRecorder($context);
        $outcomes = [];
        $applied = $this->ledger->applied($context, array_map(fn (MappedChange $m) => $m->change->seq, $chunk));
        $todo = [];

        foreach ($chunk as $mapped) {
            if ($mapped->change->seq > 0 && isset($applied[$mapped->change->seq])) {
                $outcomes[$mapped->change->index] = ChangeOutcome::Duplicate;
            } else {
                $todo[] = $mapped;
            }
        }

        $children = array_values(array_filter($todo, fn (MappedChange $m) => $m->definition->scope === 'child'));
        $outcomes += $parents->resolve($children, $context);

        $tenancy = new TenancyRowApplier($context, $conflicts);
        $groups = [];

        foreach ($todo as $mapped) {
            if (isset($outcomes[$mapped->change->index])) {
                continue;
            }

            if ($mapped->definition->tenancy) {
                $outcomes[$mapped->change->index] = $tenancy->apply($mapped);
            } else {
                $groups[$mapped->definition->scope === 'child' ? 1 : 0][$mapped->definition->entity][] = $mapped;
            }
        }

        ksort($groups);
        $reportBefore = $this->reportDays->before($context, $todo);
        $writer = new EntityWriter($context, $conflicts);

        foreach ($groups as $entities) {
            foreach ($entities as $changes) {
                $def = $changes[0]->definition;
                $outcomes += $writer->write($def, $changes);

                if ($def->children !== [] && $writer->written !== []) {
                    $this->backfillChildren($def, $writer->written, $context);
                }
            }
        }

        // §10.1: a customer's balance and points are the sum of their ledger, never a till's cached figures.
        $this->balances->afterPush($context->companyId, $todo);
        $this->reportDays->after($context, $todo, $outcomes, $reportBefore);

        $accepted = [];

        foreach ($chunk as $mapped) {
            $outcome = $outcomes[$mapped->change->index];

            if ($outcome instanceof ChangeOutcome) {
                $accepted[] = ['change' => $mapped->change, 'outcome' => $outcome];
            }
        }

        $this->ledger->record($context, $accepted);
        $conflicts->flush();

        return $outcomes;
    }

    /**
     * Children stored before their parent got no till; now that the parent is here, copy its register_id.
     *
     * O(parents written): the children are found through their indexed parent column only (CompanyRows: with
     * `company_id` in the WHERE, SQLite walked every child row of the business), then updated by primary key.
     *
     * @param  list<string>  $parentIds
     */
    private function backfillChildren(EntityDefinition $parent, array $parentIds, SyncContext $context): void
    {
        if (! in_array('register_id', $parent->columns, true)) {
            return;
        }

        foreach ($parent->children as $childEntity) {
            $child = EntityRegistry::get($childEntity);

            if (! $child->hasScopeColumn('register_id') || $child->parent === null) {
                continue;
            }

            $orphans = CompanyRows::ids($child->table, $context->companyId, $child->parent['column'], $parentIds, fn ($query) => $query->whereNull('register_id'));

            if ($orphans === []) {
                continue;
            }

            $grammar = DB::table($child->table)->getGrammar();
            $subquery = sprintf(
                '(select %s from %s where %s = %s)',
                $grammar->wrap($parent->table.'.register_id'),
                $grammar->wrapTable($parent->table),
                $grammar->wrap($parent->table.'.id'),
                $grammar->wrap($child->table.'.'.$child->parent['column']),
            );

            foreach (array_chunk($orphans, CompanyRows::CHUNK) as $ids) {
                DB::table($child->table)->whereIn('id', $ids)->whereNull('register_id')->update(['register_id' => DB::raw($subquery)]);
            }
        }
    }
}
