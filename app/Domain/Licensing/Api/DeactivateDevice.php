<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHash;
use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceDevice;
use App\Domain\Licensing\Support\InstallRelease;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/devices/deactivate` for a per-till licence (contract v1.3.1 §17.7, §17.15): the till gives its
 * key back (till removed, moving PC), so the key can be activated elsewhere without staff doing Release.
 *
 * - The till is identified by its `installId` (body, else `X-SSPOS-Install-Id`) and `registerId` (the till's
 *   own id from `existingIds`, or our register id). No branch key: per-till licences have none.
 * - Idempotent by registerId: a till already released gets the same reply. Unknown → 404 device.not_found.
 * - Reply: `seat: "deactivated"`, the branch's seats in use and maxRegisters; `apiKeyRevoked: false` and no
 *   transfer code (those belong to the branch sync link, §17.4).
 */
class DeactivateDevice
{
    public function __construct(
        private readonly InstallRelease $release,
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException device.not_found
     */
    public function handle(string $registerId, TillRequest $till, string $reason, ?string $note): array
    {
        $now = CarbonImmutable::now()->startOfSecond();

        $licence = DB::transaction(function () use ($registerId, $till, $reason, $note, $now) {
            $bound = Licence::withoutCompanyScope()->where('device_id', $till->installId)->lockForUpdate()->get()
                ->first(fn (Licence $licence) => self::isRegister($licence, $registerId));

            if ($bound === null) {
                return $this->released($registerId, $till) ?? throw LicenceApiErrors::deviceNotFound();
            }

            $this->devices->record($bound, $till, DeviceHistory::RELEASED, $now);
            $before = $this->release->apply($bound, $now);
            $this->audit->record('licence.released', $bound, $before, InstallRelease::after(), $till, array_filter([
                'reason' => mb_substr($reason, 0, 40),
                'note' => $note !== null ? mb_substr($note, 0, 200) : null,
            ]));

            return $bound;
        });

        return [
            'registerId' => $registerId,
            'seat' => 'deactivated',
            'seatsInUse' => LicenceToken::registersInUse($licence->branch_id),
            'maxRegisters' => LicenceToken::maxRegisters($licence->branch_id),
            'apiKeyRevoked' => false,
            'transferCode' => null,
            'transferCodeExpiresAt' => null,
            'portalTimeUtc' => ApiDate::format($now),
            'messages' => [],
        ];
    }

    /** An install already released from a licence of this register (a repeated deactivate). */
    private function released(string $registerId, TillRequest $till): ?Licence
    {
        $licenceIds = LicenceDevice::withoutCompanyScope()
            ->where('device_hash', DeviceHash::of($till->installId))
            ->whereNotNull('released_at')
            ->pluck('licence_id');

        return Licence::withoutCompanyScope()->whereIn('id', $licenceIds)->get()
            ->first(fn (Licence $licence) => self::isRegister($licence, $registerId));
    }

    private static function isRegister(Licence $licence, string $registerId): bool
    {
        return $licence->register_id === $registerId || ($licence->existing_ids['registerId'] ?? null) === $registerId;
    }
}
