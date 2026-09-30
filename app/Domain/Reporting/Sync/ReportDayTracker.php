<?php

namespace App\Domain\Reporting\Sync;

use App\Domain\Reporting\Jobs\ProcessDirtyReportDaysJob;
use App\Domain\Reporting\Support\DirtyDays;
use App\Domain\Reporting\Support\SaleDayStamper;
use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\CompanyRows;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\SyncContext;

/**
 * The reporting hook in the sync apply path (module 3.1, DASHBOARD.md §1.1 step 4 and §4.8):
 *
 * - `before()` (in the chunk's transaction, before the writes): the stored shop and trading day of every sale the
 *   chunk touches — a `Sale` row's own id, a `SaleLine` / `SalePayment` / `SaleVat` row's `saleId`;
 * - `after()` (same transaction, after the writes): stamps the trading day of the sales written, then marks the
 *   old and new (shop, day) of every sale an applied change touched as dirty;
 * - `dispatch()` (after the batch): queues the business's rebuild job once, after the commit.
 *
 * Duplicates, stale and echoed rows mark nothing. A child that arrives before its sale marks nothing until the sale
 * lands (then the sale's own change marks its day).
 */
final class ReportDayTracker
{
    public const ENTITIES = ['Sale', 'SaleLine', 'SalePayment', 'SaleVat'];

    public function __construct(private readonly SaleDayStamper $stamper) {}

    /**
     * @param  list<MappedChange>  $todo
     * @return array<string, array{branch: string|null, day: string|null}> sale id => stored shop and day
     */
    public function before(SyncContext $context, array $todo): array
    {
        $ids = $this->saleIds($context, $this->relevant($todo));

        return $ids === [] ? [] : $this->stored($context->companyId, $ids);
    }

    /**
     * @param  list<MappedChange>  $todo
     * @param  array<int, ChangeOutcome|Rejection>  $outcomes
     * @param  array<string, array{branch: string|null, day: string|null}>  $before
     */
    public function after(SyncContext $context, array $todo, array $outcomes, array $before): void
    {
        $applied = array_values(array_filter(
            $this->relevant($todo),
            fn (MappedChange $m) => ($outcomes[$m->change->index] ?? null) === ChangeOutcome::Applied,
        ));

        if ($applied === []) {
            return;
        }

        $written = array_map(fn (MappedChange $m) => $m->change->entityId, array_filter($applied, fn (MappedChange $m) => $m->definition->entity === 'Sale'));
        $now = $this->stamper->stamp($context->companyId, array_values($written));
        $days = [];

        foreach ($this->saleIds($context, $applied) as $id) {
            foreach ([$before[$id] ?? null, $now[$id] ?? null] as $at) {
                if ($at !== null && $at['branch'] !== null && $at['day'] !== null) {
                    $days[$at['branch'].'|'.$at['day']] = ['branch' => $at['branch'], 'day' => $at['day']];
                }
            }
        }

        DirtyDays::mark($context->companyId, $days);
    }

    /**
     * Queue the business's rebuild after the commit when the batch applied any sale row.
     *
     * @param  list<MappedChange>  $mapped
     * @param  array<int, ChangeOutcome|Rejection>  $outcomes
     */
    public static function dispatch(string $companyId, array $mapped, array $outcomes): void
    {
        foreach ($mapped as $m) {
            if (in_array($m->definition->entity, self::ENTITIES, true) && ($outcomes[$m->change->index] ?? null) === ChangeOutcome::Applied) {
                ProcessDirtyReportDaysJob::dispatch($companyId);

                return;
            }
        }
    }

    /**
     * @param  list<MappedChange>  $changes
     * @return list<MappedChange>
     */
    private function relevant(array $changes): array
    {
        return array_values(array_filter($changes, fn (MappedChange $m) => in_array($m->definition->entity, self::ENTITIES, true)));
    }

    /**
     * @param  list<MappedChange>  $changes
     * @return list<string>
     */
    private function saleIds(SyncContext $context, array $changes): array
    {
        $ids = [];
        $lookups = [];

        foreach ($changes as $m) {
            if ($m->definition->entity === 'Sale') {
                $ids[$m->change->entityId] = true;
            } elseif ($m->parentId !== null) {
                $ids[$m->parentId] = true;
            } elseif ($m->row === null) {
                // A child deleted without a payload: its sale is the stored row's.
                $lookups[$m->definition->entity][] = $m->change->entityId;
            }
        }

        foreach ($lookups as $entity => $childIds) {
            foreach (CompanyRows::whereIn(EntityRegistry::get($entity)->table, $context->companyId, 'id', $childIds, ['sale_id']) as $row) {
                if (is_string($row->sale_id) && $row->sale_id !== '') {
                    $ids[$row->sale_id] = true;
                }
            }
        }

        return array_map('strval', array_keys($ids));
    }

    /**
     * @param  list<string>  $ids
     * @return array<string, array{branch: string|null, day: string|null}>
     */
    private function stored(string $companyId, array $ids): array
    {
        $stored = [];

        foreach (CompanyRows::whereIn('sales', $companyId, 'id', $ids, ['id', 'branch_id', 'trading_day']) as $row) {
            $stored[(string) $row->id] = [
                'branch' => $row->branch_id === null ? null : (string) $row->branch_id,
                'day' => $row->trading_day === null ? null : substr((string) $row->trading_day, 0, 10),
            ];
        }

        return $stored;
    }
}
