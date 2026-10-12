<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/** Revokes a partner API key: refused from the next call (401 auth.key_revoked). Audited. */
class RevokePartnerKey
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(PartnerKey $key): PartnerKey
    {
        if ($key->isRevoked()) {
            throw ValidationException::withMessages(['key' => 'This key is already revoked.']);
        }

        $key->revoked_at = CarbonImmutable::now();
        $key->save();

        $this->audit->handle('partner.key_revoked', $key->partner, null, null, ['key_id' => $key->id, 'name' => $key->name, 'ending' => $key->key_last4]);

        return $key;
    }
}
