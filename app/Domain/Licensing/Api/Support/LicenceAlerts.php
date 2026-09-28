<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Raises admin alerts from the licence API. One open alert per licence + type + PC: repeats count up
 * (`count`, `last_seen_at`, latest details). Written in its own transaction, before the API answers with an
 * error, so the alert survives the error reply.
 */
final class LicenceAlerts
{
    /**
     * @param  array<string, mixed>  $extra  More non-secret details.
     */
    public function raise(Licence $licence, LicenceAlertType $type, TillRequest $request, array $extra = []): LicenceAlert
    {
        $now = CarbonImmutable::now();
        $fingerprint = DeviceHash::of($request->installId);
        $details = [
            ...$request->describePc(),
            'boundDeviceName' => $licence->device_name,
            'boundDeviceIdEnding' => DeviceHash::ending($licence->device_id),
            'keyLast4' => $licence->key_last4,
            ...$extra,
        ];

        $alert = DB::transaction(function () use ($licence, $type, $fingerprint, $details, $now) {
            $open = LicenceAlert::withoutCompanyScope()
                ->where('licence_id', $licence->id)
                ->where('type', $type->value)
                ->where('fingerprint', $fingerprint)
                ->open()
                ->lockForUpdate()
                ->first();

            if ($open !== null) {
                $open->forceFill(['count' => $open->count + 1, 'last_seen_at' => $now, 'details' => $details])->save();

                return $open;
            }

            return LicenceAlert::withoutCompanyScope()->create([
                'company_id' => $licence->company_id,
                'licence_id' => $licence->id,
                'type' => $type,
                'fingerprint' => $fingerprint,
                'details' => $details,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'count' => 1,
            ]);
        });

        Log::warning('Licence alert raised.', [
            'alert' => $type->value,
            'licenceId' => $licence->id,
            'companyId' => $licence->company_id,
            'maskedKey' => LicenceKey::mask($licence->key_last4),
            'count' => $alert->count,
        ]);

        return $alert;
    }

    /** Open alerts of one company, for the admin tenant page. */
    public static function openCount(string $companyId): int
    {
        return LicenceAlert::withoutCompanyScope()->where('company_id', $companyId)->open()->count();
    }
}
