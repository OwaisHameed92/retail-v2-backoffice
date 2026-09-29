<?php

namespace App\Domain\TillData\Sync;

use App\Domain\TillData\EntityRegistry;
use App\Domain\TillData\Sync\Data\MappedChange;
use App\Domain\TillData\Sync\Data\Rejection;
use App\Domain\TillData\Sync\Data\SyncChange;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use Illuminate\Support\Facades\Log;

/**
 * Batch bookkeeping for ApplySyncChanges: which changes are never stored (skipped), and which seqs the push reply's
 * `receivedAt` covers (ChangeLedger::receivedAt).
 */
final class NeverStored
{
    /**
     * Never stored (contract §10, §10.3): rows of a `local` table (an older till's SyncState) and deny-listed
     * settings (secrets, keys, register-scope, one PC's bookkeeping). Acknowledged so the till moves on.
     */
    public static function matches(SyncChange $change): bool
    {
        return EntityRegistry::isLocal($change->entity)
            || ($change->entity === 'Setting' && is_array($change->payload)
                && SettingSyncPolicy::isLocalOnly($change->payload['scope'] ?? null, $change->payload['key'] ?? null));
    }

    /**
     * Seqs this call wrote to the ledger (their receivedAt is now).
     *
     * @param  list<MappedChange|SyncChange|Rejection>  $items
     * @param  array<int, ChangeOutcome|Rejection>  $outcomes
     * @return list<int>
     */
    public static function storedSeqs(array $items, array $outcomes): array
    {
        $seqs = [];

        foreach ($items as $i => $item) {
            if ($item instanceof MappedChange && $item->change->seq > 0 && $outcomes[$i] instanceof ChangeOutcome && $outcomes[$i] !== ChangeOutcome::Duplicate) {
                $seqs[] = $item->change->seq;
            }
        }

        return $seqs;
    }

    /**
     * Every accepted seq of the batch (a retry's duplicates included).
     *
     * @param  list<MappedChange|SyncChange|Rejection>  $items
     * @param  array<int, ChangeOutcome|Rejection>  $outcomes
     * @return list<int>
     */
    public static function acceptedSeqs(array $items, array $outcomes): array
    {
        $seqs = [];

        foreach ($items as $i => $item) {
            $seq = ($item instanceof MappedChange ? $item->change->seq : $item->seq);

            if ($seq !== null && $seq > 0 && $outcomes[$i] instanceof ChangeOutcome) {
                $seqs[] = $seq;
            }
        }

        return $seqs;
    }

    /**
     * Say once per batch that rows were dropped unstored (entity names and setting keys only, never values).
     *
     * @param  list<MappedChange|SyncChange|Rejection>  $items
     * @param  array<int, ChangeOutcome|Rejection>  $outcomes
     */
    public static function log(array $items, array $outcomes, SyncContext $context): void
    {
        $skipped = [];

        foreach ($items as $i => $item) {
            if ($item instanceof SyncChange && $outcomes[$i] === ChangeOutcome::Skipped) {
                $what = $item->entity === 'Setting' && is_string($item->payload['key'] ?? null) ? 'Setting '.mb_substr($item->payload['key'], 0, 80) : $item->entity;
                $skipped[$what] = ($skipped[$what] ?? 0) + 1;
            }
        }

        if ($skipped !== []) {
            Log::info('Till sync acknowledged rows that are never stored (local tables, deny-listed settings).', [
                'company_id' => $context->companyId,
                'branch_id' => $context->branchId,
                'rows' => array_slice($skipped, 0, 20, true),
            ]);
        }
    }
}
