<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Tenancy\Models\Branch;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Stops every sync key of a branch at once, including replaced keys still in their grace days (module 2.1).
 * The branch's tills get 401 auth.key_revoked. No new key is sent to the main till until an admin generates one
 * (SyncKeyDelivery never re-issues after a revoke).
 */
class RevokeSyncKeys
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException when the branch has no working key
     */
    public function handle(Branch $branch, ?Model $admin = null): int
    {
        return DB::transaction(function () use ($branch, $admin) {
            $now = CarbonImmutable::now();
            $keys = SyncKey::withoutCompanyScope()->where('branch_id', $branch->id)->whereNull('revoked_at')->lockForUpdate()->get()
                ->filter(fn (SyncKey $key) => $key->isUsable($now));

            if ($keys->isEmpty()) {
                throw ValidationException::withMessages(['sync_key' => "{$branch->name} has no sync key to revoke."]);
            }

            foreach ($keys as $key) {
                $key->revoked_at = $now;
                $key->rotate_requested_at = null;
                $key->save();
            }

            $this->audit->handle('sync_key.revoked', $branch, null, ['keys_last4' => $keys->pluck('key_last4')->values()->all()], actor: $admin, companyId: $branch->company_id);

            return $keys->count();
        });
    }
}
