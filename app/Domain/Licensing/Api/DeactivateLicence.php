<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceLookup;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/licence/deactivate`: the bound PC gives its key back (uninstall, moving PC), so the next PC can
 * activate without staff doing "Reset PC". Status and dates are kept.
 *
 * - Nothing bound: `{released: true}` (idempotent).
 * - Another PC: 403 licence.device_mismatch and a `deviceMismatch` alert: only the bound PC can release.
 * - Revoked: 403 licence.revoked (a revoked licence keeps its PC for history).
 */
class DeactivateLicence
{
    public function __construct(
        private readonly LicenceLookup $lookup,
        private readonly LicenceAlerts $alerts,
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
    ) {}

    /**
     * @return array{released: true}
     *
     * @throws ApiException
     */
    public function handle(TillRequest $request): array
    {
        $now = CarbonImmutable::now();
        $licence = $this->lookup->find($request);

        if ($licence->isRevoked()) {
            throw LicenceApiErrors::revoked();
        }

        if ($licence->isBound() && $licence->device_id !== $request->deviceId) {
            $this->alerts->raise($licence, LicenceAlertType::DeviceMismatch, $request, ['attempted' => 'deactivate']);
            $this->devices->record($licence, $request, DeviceHistory::REJECTED, $now);

            throw LicenceApiErrors::deviceMismatch(true);
        }

        DB::transaction(function () use ($licence, $request, $now) {
            $licence = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->id);

            if (! $licence->isBound()) {
                return;
            }

            if ($licence->device_id !== $request->deviceId) {
                throw LicenceApiErrors::deviceMismatch(true);
            }

            $before = ['device_id' => $licence->device_id, 'device_name' => $licence->device_name, 'bound_at' => $licence->bound_at?->toIso8601String()];

            $licence->device_id = null;
            $licence->device_name = null;
            $licence->bound_at = null;
            TillAudit::touch($licence, $request, $now);
            $licence->save();

            $this->devices->record($licence, $request, DeviceHistory::RELEASED, $now);
            $this->audit->record('licence.released', $licence, $before, ['device_id' => null, 'device_name' => null, 'bound_at' => null], $request, [
                'device_name' => $before['device_name'],
            ]);
        });

        return ['released' => true];
    }
}
