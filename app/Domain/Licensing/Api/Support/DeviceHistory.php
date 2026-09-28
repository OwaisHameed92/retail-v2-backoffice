<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceDevice;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Remembers every PC (till install) that used a licence key (hashed installId, name, last IP and app version,
 * what happened). Support uses it to see "which PCs have tried this key". `released_at` marks an install that
 * was released from the key (admin Release, devices/deactivate, reissued key): its next validate is `released`.
 */
final class DeviceHistory
{
    public const ACTIVATED = 'activated';

    public const REINSTALLED = 'reinstalled';

    public const CHECKED_IN = 'checkedIn';

    public const RELEASED = 'released';

    public const REJECTED = 'rejected';

    public function record(Licence $licence, TillRequest $request, string $outcome, CarbonImmutable $now): void
    {
        $hash = DeviceHash::of($request->installId);
        $values = array_filter([
            'device_name' => $request->deviceName,
            'last_ip' => $request->ip,
            'last_app_version' => $request->appVersion !== null ? mb_substr($request->appVersion, 0, 50) : null,
        ], fn (?string $value) => $value !== null) + ['last_outcome' => $outcome, 'last_seen_at' => $now];

        if ($outcome === self::ACTIVATED) {
            $values['released_at'] = null;
        }

        $existing = LicenceDevice::withoutCompanyScope()->where('licence_id', $licence->id)->where('device_hash', $hash)->first();

        if ($existing !== null) {
            $existing->forceFill($values + ['times_seen' => $existing->times_seen + 1])->save();

            return;
        }

        try {
            LicenceDevice::withoutCompanyScope()->create($values + [
                'company_id' => $licence->company_id,
                'licence_id' => $licence->id,
                'device_hash' => $hash,
                'first_seen_at' => $now,
                'times_seen' => 1,
            ]);
        } catch (UniqueConstraintViolationException) {
            // A parallel request from the same PC wrote the row first: history is best effort.
        }
    }

    /** Marks an install as released from the licence (creates its history row when there is none). */
    public function markReleased(Licence $licence, string $installId, ?string $deviceName, CarbonImmutable $now): void
    {
        $hash = DeviceHash::of($installId);
        $row = LicenceDevice::withoutCompanyScope()->where('licence_id', $licence->id)->where('device_hash', $hash)->first();

        if ($row !== null) {
            $row->forceFill(['released_at' => $now, 'last_outcome' => self::RELEASED])->save();

            return;
        }

        LicenceDevice::withoutCompanyScope()->create([
            'company_id' => $licence->company_id,
            'licence_id' => $licence->id,
            'device_hash' => $hash,
            'device_name' => $deviceName,
            'last_outcome' => self::RELEASED,
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'times_seen' => 1,
            'released_at' => $now,
        ]);
    }

    /** Whether this install was released from the licence (and has not activated it again since). */
    public static function wasReleased(Licence $licence, string $installId): bool
    {
        return LicenceDevice::withoutCompanyScope()
            ->where('licence_id', $licence->id)
            ->where('device_hash', DeviceHash::of($installId))
            ->whereNotNull('released_at')
            ->exists();
    }
}
