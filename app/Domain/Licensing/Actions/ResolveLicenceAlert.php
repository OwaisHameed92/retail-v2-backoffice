<?php

namespace App\Domain\Licensing\Actions;

use App\Domain\Admin\Models\Admin;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Staff mark a licence API alert as dealt with. A later repeat from the same PC opens a new alert.
 */
class ResolveLicenceAlert
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(LicenceAlert $alert, ?Admin $admin = null): LicenceAlert
    {
        return DB::transaction(function () use ($alert, $admin) {
            $alert = LicenceAlert::withoutCompanyScope()->lockForUpdate()->findOrFail($alert->id);

            if (! $alert->isOpen()) {
                return $alert;
            }

            $alert->forceFill(['resolved_at' => CarbonImmutable::now(), 'resolved_by' => $admin?->id])->save();

            $licence = Licence::withoutCompanyScope()->withTrashed()->find($alert->licence_id);
            $this->audit->handle('licence.alert_resolved', $licence, null, ['alert' => $alert->type->value], [
                'alert_id' => $alert->id,
                'label' => $alert->type->label(),
                'count' => $alert->count,
            ], companyId: $alert->company_id);

            return $alert;
        });
    }
}
