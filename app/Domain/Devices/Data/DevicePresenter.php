<?php

namespace App\Domain\Devices\Data;

use App\Domain\Devices\Enums\DeviceKind;
use App\Domain\Devices\Enums\DeviceStatus;
use App\Domain\Devices\Models\DeviceRow;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\AppVersion;
use Carbon\CarbonImmutable;

/**
 * A Devices list row as React reads it (`resources/js/components/admin/devices/types.ts`). Dates are ISO UTC; the
 * page shows them in the shops' time zone. Admin only: the till's install id in full (as on the licence page), the
 * licence key's last 4 only, never a hash or token.
 */
final class DevicePresenter
{
    /**
     * @param  Licence|null  $licence  The till's licence (loaded with company, branch and till); null for a handheld.
     * @param  string|null  $gate  Versions older than this are flagged "Old".
     * @return array<string, mixed>
     */
    public static function row(DeviceRow $row, ?Licence $licence, CarbonImmutable $now, ?string $gate = null): array
    {
        $kind = DeviceKind::from($row->kind);
        $state = $licence?->state($now);
        $status = match (true) {
            $state !== null => DeviceStatus::forTill($state, $licence->isBound(), $now),
            $kind === DeviceKind::Handheld => DeviceStatus::tryFrom((string) $row->status) ?? DeviceStatus::SignedOut,
            // A till whose licence vanished between the two reads.
            default => DeviceStatus::Revoked,
        };
        $version = $row->app_version;

        return [
            'id' => $row->id,
            'kind' => $kind->value,
            'company' => ['id' => $row->company_id, 'name' => $row->company_name],
            'branch' => ['id' => $row->branch_id, 'name' => $row->branch_name, 'code' => $row->branch_code],
            'name' => $row->name,
            'code' => $row->code,
            'isMainTill' => $kind === DeviceKind::Till ? (bool) $row->is_main_till : null,
            'appVersion' => $version,
            'appOutdated' => $version !== null && $gate !== null && AppVersion::compare($version, $gate) < 0,
            'lastSeenAt' => $row->last_seen_at === null ? null : ApiDate::format($row->last_seen_at),
            'status' => $status->value,
            'statusLabel' => $status->label(),
            // The licence's own status and why the till is locked, for the tooltip ("Trial", "The branch is deactivated.").
            'licenceStatus' => $state?->status->value,
            'licenceStatusLabel' => $state?->status->label(),
            'statusReason' => $state?->reason,
            'endsAt' => $state?->endsAt === null ? null : ApiDate::format($state->endsAt),
            'licenceId' => $kind === DeviceKind::Till ? $row->id : null,
            'keyLast4' => $row->key_last4,
            'installId' => $row->install_id,
            'deviceName' => $row->device_name,
            'clockSkewSeconds' => $row->clock_skew_seconds,
            'canRelease' => $licence !== null && $licence->isBound() && ! $licence->isRevoked(),
        ];
    }
}
