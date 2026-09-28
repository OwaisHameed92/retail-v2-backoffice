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
 * "Send a new key to the till" (module 2.1): the branch's main till gets a fresh key as `apiKey` at its next
 * licence check (validate-reply "a replacement branch key"); the current key keeps working until then and for
 * 7 days after. Nobody sees the new key, so it cannot leak through the admin screen.
 */
class RequestSyncKeyRotation
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException when the branch has no current key
     */
    public function handle(Branch $branch, ?Model $admin = null): SyncKey
    {
        return DB::transaction(function () use ($branch, $admin) {
            $key = SyncKey::withoutCompanyScope()->where('branch_id', $branch->id)->current()->lockForUpdate()->first()
                ?? throw ValidationException::withMessages(['sync_key' => "{$branch->name} has no sync key yet. Generate one first."]);

            $key->rotate_requested_at = CarbonImmutable::now();
            $key->save();

            $this->audit->handle('sync_key.rotation_requested', $key, null, ['key_last4' => $key->key_last4], actor: $admin, companyId: $branch->company_id);

            return $key;
        });
    }
}
