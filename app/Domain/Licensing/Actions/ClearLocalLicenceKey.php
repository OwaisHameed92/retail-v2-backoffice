<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Licensing\Models\LocalLicenceKeyRefusal;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;

/**
 * Clears a record of the local key register (contract v1.4.1 §17.16 checklist "let an admin clear a record"): the
 * dealer moved the key to a new PC, so the next report from any install is a first sighting again. Its refusals go
 * with it. Audited (who, when, which key and install code).
 */
class ClearLocalLicenceKey
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(LocalLicenceKey $key, string $reason): void
    {
        DB::transaction(function () use ($key, $reason) {
            $before = $key->only(['licence_id', 'install_code', 'device_name', 'business_name', 'report_count', 'refused_count']);

            LocalLicenceKeyRefusal::query()->where('local_licence_key_id', $key->id)->delete();
            $key->delete();

            $this->audit->handle('local_key.cleared', $key, $before, null, ['reason' => $reason], companyId: $key->company_id);
        });
    }
}
