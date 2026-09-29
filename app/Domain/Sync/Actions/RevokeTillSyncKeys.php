<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `devices/deactivate` of the till that was sent the branch's sync key (contract v1.4.1 §17.7, ANSWERS-2026-09-29
 * §1): every usable key delivered to that install stops working now, so a restored old PC cannot push. When that
 * was the branch's current key, a new one replaces it (rotation, never shown or sent): the next main till to
 * check in gets its own key as usual (SyncKeyDelivery). Returns whether a key was revoked (`apiKeyRevoked`).
 *
 * A repeated deactivate finds nothing left to revoke and still answers true (the reply of the first call).
 */
class RevokeTillSyncKeys
{
    public function __construct(
        private readonly RecordAudit $audit,
        private readonly IssueSyncKey $issue,
    ) {}

    public function handle(Branch $branch, string $installId): bool
    {
        return DB::transaction(function () use ($branch, $installId) {
            $now = CarbonImmutable::now();
            $delivered = SyncKey::withoutCompanyScope()
                ->where('branch_id', $branch->id)
                ->where('delivered_install_id', $installId)
                ->lockForUpdate()
                ->get();
            $usable = $delivered->filter(fn (SyncKey $key) => $key->isUsable($now));

            if ($usable->isEmpty()) {
                return $delivered->contains(fn (SyncKey $key) => $key->revoked_at !== null);
            }

            $wasCurrent = $usable->contains(fn (SyncKey $key) => $key->isCurrent());

            foreach ($usable as $key) {
                $key->revoked_at = $now;
                $key->rotate_requested_at = null;
                $key->save();
            }

            $this->audit->handle('sync_key.revoked', $branch, null, [
                'keys_last4' => $usable->pluck('key_last4')->values()->all(),
                'reason' => 'till_deactivated',
                'install' => substr($installId, -6),
            ], companyId: $branch->company_id);

            if ($wasCurrent) {
                $this->issue->handle($branch, SyncKeySource::Till);
            }

            return true;
        });
    }
}
