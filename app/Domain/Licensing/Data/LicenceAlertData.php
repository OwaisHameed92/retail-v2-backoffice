<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Admin\Models\Admin;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;

/**
 * Licence API alerts for the admin licence page (open first, then the last resolved ones).
 */
final class LicenceAlertData
{
    /**
     * @return list<array<string, mixed>>
     */
    public static function forLicence(Licence $licence, int $resolvedLimit = 5): array
    {
        $query = fn () => LicenceAlert::withoutCompanyScope()->where('licence_id', $licence->id);

        $alerts = $query()->open()->orderByDesc('last_seen_at')->get()
            ->concat($query()->whereNotNull('resolved_at')->orderByDesc('resolved_at')->limit($resolvedLimit)->get());

        $admins = Admin::query()->whereIn('id', $alerts->pluck('resolved_by')->filter()->unique()->all())->pluck('name', 'id');

        return $alerts->map(fn (LicenceAlert $alert) => [
            'id' => $alert->id,
            'type' => $alert->type->value,
            'label' => $alert->type->label(),
            'help' => $alert->type->help(),
            'details' => self::details($alert->details ?? []),
            'count' => $alert->count,
            'firstSeenAt' => LicenceData::date($alert->first_seen_at),
            'lastSeenAt' => LicenceData::date($alert->last_seen_at),
            'resolvedAt' => LicenceData::date($alert->resolved_at),
            'resolvedBy' => $alert->resolved_by !== null ? ($admins[$alert->resolved_by] ?? 'Former admin') : null,
        ])->values()->all();
    }

    /**
     * Only the known, non-secret detail fields, as strings.
     *
     * @param  array<string, mixed>  $details
     * @return array<string, string|null>
     */
    private static function details(array $details): array
    {
        $keys = ['deviceName', 'deviceIdEnding', 'ip', 'appVersion', 'os', 'boundDeviceName', 'boundDeviceIdEnding', 'retiredKeyLast4', 'attempted'];
        $out = [];

        foreach ($keys as $key) {
            $value = $details[$key] ?? null;
            $out[$key] = is_scalar($value) ? (string) $value : null;
        }

        return $out;
    }
}
