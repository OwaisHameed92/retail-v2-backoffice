<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\SyncKeySecret;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Makes a new sync key for a branch and returns it in plain text — the only time it exists (module 2.1). The
 * branch's current key is replaced: it keeps working for SyncKey::REPLACED_GRACE_DAYS so a till holding it can
 * switch over. `$installId` = the main till the key is being sent to in a licence reply.
 *
 * Callers: the licence API (SyncKeyDelivery, source till) and the admin "Generate key" (source admin, shown once).
 */
class IssueSyncKey
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(Branch $branch, SyncKeySource $source, ?Model $admin = null, ?string $installId = null): string
    {
        return DB::transaction(function () use ($branch, $source, $admin, $installId) {
            $now = CarbonImmutable::now();
            $plain = SyncKeySecret::generate();

            $replaced = SyncKey::withoutCompanyScope()->where('branch_id', $branch->id)->current()->lockForUpdate()->get();

            foreach ($replaced as $old) {
                $old->replaced_at = $now;
                $old->rotate_requested_at = null;
                $old->save();
            }

            $key = new SyncKey([
                'company_id' => $branch->company_id,
                'branch_id' => $branch->id,
                'key_hash' => SyncKeySecret::hash($plain),
                'key_last4' => SyncKeySecret::last4($plain),
                'source' => $source,
                'created_by' => $admin?->getKey(),
            ]);

            if ($installId !== null) {
                $key->delivered_install_id = $installId;
                $key->delivered_at = $now;
            }

            $key->save();

            $this->audit->handle(
                $replaced->isEmpty() ? 'sync_key.issued' : 'sync_key.rotated',
                $key,
                $replaced->isEmpty() ? null : ['key_last4' => $replaced->first()->key_last4],
                ['key_last4' => $key->key_last4, 'source' => $source->value, 'branch_id' => $branch->id],
                array_filter(['delivered_to_install' => $installId === null ? null : substr($installId, -6)]),
                actor: $admin,
                companyId: $branch->company_id,
            );

            return $plain;
        });
    }
}
