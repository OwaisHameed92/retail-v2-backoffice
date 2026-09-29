<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\Sync\Data\SyncChange;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * The sync_applied_changes ledger: (company, sending branch, stream, seq) of every accepted pushed change. A retry of
 * the same seq is a duplicate and changes nothing. `stream` is '' for the branch's ChangeLog (delta push) and the
 * upload id for an initial upload (contract §17.8: its rows are numbered 1…N and keyed by (uploadId, seq)). Pull-shaped changes (seq 0) are not recorded; for them the row's
 * version alone makes a replay a no-op.
 */
final class ChangeLedger
{
    private const COLUMNS = ['company_id', 'branch_id', 'stream', 'seq', 'entity', 'entity_id', 'version', 'op', 'outcome', 'applied_at'];

    /**
     * @param  list<int>  $seqs
     * @return array<int, true> seqs already applied
     */
    public function applied(SyncContext $context, array $seqs): array
    {
        $seqs = array_values(array_filter($seqs, fn (int $seq) => $seq > 0));
        $found = [];

        foreach (array_chunk($seqs, 1000) as $chunk) {
            $query = DB::table('sync_applied_changes')
                ->where('company_id', $context->companyId)
                ->where('branch_id', $context->branchId)
                ->where('stream', $context->stream)
                ->whereIn('seq', $chunk);

            foreach ($query->pluck('seq') as $seq) {
                $found[(int) $seq] = true;
            }
        }

        return $found;
    }

    /**
     * @param  list<array{change: SyncChange, outcome: ChangeOutcome}>  $accepted
     */
    public function record(SyncContext $context, array $accepted): void
    {
        $rows = [];

        foreach ($accepted as ['change' => $change, 'outcome' => $outcome]) {
            if ($change->seq > 0 && $outcome !== ChangeOutcome::Duplicate) {
                $rows[] = [
                    'company_id' => $context->companyId,
                    'branch_id' => $context->branchId,
                    'stream' => $context->stream,
                    'seq' => $change->seq,
                    'entity' => $change->entity,
                    'entity_id' => $change->entityId,
                    'version' => $change->version,
                    'op' => $change->op,
                    'outcome' => $outcome->value,
                    'applied_at' => $context->now,
                ];
            }
        }

        BulkWriter::insertOrIgnore('sync_applied_changes', self::COLUMNS, $rows);
    }

    /**
     * The push reply's `receivedAt` (contract v1.4 §7, §6.1): when the batch's rows were durably stored, by our
     * clock, taken after the commit. Rows this call stored are stamped with that time; a retry answers with the
     * latest time the ledger holds for its (branch, seq) values — the first time, never "now". A batch with nothing
     * in the ledger (all rejected, or pull-shaped seq 0) answers now.
     *
     * @param  list<int>  $stored  seqs this call wrote
     * @param  list<int>  $accepted  every accepted seq of the batch
     */
    public function receivedAt(SyncContext $context, array $stored, array $accepted): string
    {
        $now = now('UTC')->format('Y-m-d H:i:s');

        foreach (array_chunk(array_values(array_unique($stored)), 1000) as $chunk) {
            $this->scoped($context, $chunk)->update(['applied_at' => $now]);
        }

        $latest = null;

        if ($stored === []) {
            foreach (array_chunk(array_values(array_unique($accepted)), 1000) as $chunk) {
                $max = $this->scoped($context, $chunk)->max('applied_at');
                $latest = $max !== null && ($latest === null || (string) $max > $latest) ? substr((string) $max, 0, 19) : $latest;
            }
        }

        return str_replace(' ', 'T', $latest ?? $now).'Z';
    }

    /**
     * @param  list<int>  $seqs
     */
    private function scoped(SyncContext $context, array $seqs): Builder
    {
        return DB::table('sync_applied_changes')
            ->where('company_id', $context->companyId)
            ->where('branch_id', $context->branchId)
            ->where('stream', $context->stream)
            ->whereIn('seq', $seqs);
    }
}
