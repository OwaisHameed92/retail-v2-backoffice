<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceLookup;
use App\Domain\Licensing\Api\Support\LicenceReply;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/licence/check-in`: the bound PC's daily call. Answers 200 with a fresh signed token for every
 * status, including suspended, expired and revoked (the till obeys the status in the token).
 *
 * - Not the bound PC: 403 licence.device_mismatch, plus a `deviceMismatch` alert when the key is bound to
 *   another PC. After "Reset PC" (nothing bound) the old PC just gets the 403: it must activate again.
 * - Records last check-in, IP and app version. Only an app version change is audited (daily check-ins are not).
 */
class CheckInLicence
{
    public function __construct(
        private readonly LicenceLookup $lookup,
        private readonly LicenceAlerts $alerts,
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
        private readonly LicenceReply $reply,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function handle(TillRequest $request): array
    {
        $now = CarbonImmutable::now();
        $licence = $this->lookup->find($request);

        if ($licence->device_id !== $request->deviceId) {
            if ($licence->isBound()) {
                $this->alerts->raise($licence, LicenceAlertType::DeviceMismatch, $request, ['tokenId' => $request->tokenId]);
            }

            $this->devices->record($licence, $request, DeviceHistory::REJECTED, $now);

            throw LicenceApiErrors::deviceMismatch($licence->isBound());
        }

        $licence = DB::transaction(function () use ($licence, $request, $now) {
            $licence = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->id);

            if ($licence->device_id !== $request->deviceId) {
                throw LicenceApiErrors::deviceMismatch($licence->isBound());
            }

            $previousVersion = $licence->last_app_version;
            TillAudit::touch($licence, $request, $now);
            $licence->save();

            $this->devices->record($licence, $request, DeviceHistory::CHECKED_IN, $now);

            if ($request->appVersion !== null && $previousVersion !== null && $previousVersion !== $licence->last_app_version) {
                $this->audit->record('licence.app_updated', $licence, ['last_app_version' => $previousVersion], ['last_app_version' => $licence->last_app_version], $request);
            }

            return $licence;
        });

        return $this->reply->checkIn($licence, $now);
    }
}
