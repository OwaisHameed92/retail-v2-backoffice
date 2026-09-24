<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceDevice;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Remembers every PC that used a licence key (hashed device id, name, last IP and app version, what happened).
 * Support uses it to see "which PCs have tried this key".
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
        $hash = DeviceHash::of($request->deviceId);
        $values = array_filter([
            'device_name' => $request->deviceName,
            'last_ip' => $request->ip,
            'last_app_version' => $request->appVersion !== null ? mb_substr($request->appVersion, 0, 50) : null,
        ], fn (?string $value) => $value !== null) + ['last_outcome' => $outcome, 'last_seen_at' => $now];

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
}
