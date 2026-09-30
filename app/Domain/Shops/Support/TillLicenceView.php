<?php

namespace App\Domain\Shops\Support;

use App\Domain\Licensing\Data\LicenceData;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use Carbon\CarbonImmutable;

/**
 * A till's licence as the business may see it (module 4.7, read only): the effective status (what the till is told),
 * dates, plan and features and the key's last four characters. Never the key, its hash, the token or install ids.
 */
final class TillLicenceView
{
    /**
     * @return array{id: string, status: string, statusLabel: string, reason: string|null, canTrade: bool, isTrial: bool, endsAt: string|null, graceEndsAt: string|null, activatedAt: string|null, activateBy: string|null, lastValidatedAt: string|null, appVersion: string|null, maskedKey: string, plan: string|null, features: list<array{value: string, label: string}>}
     */
    public static function of(Licence $licence, CarbonImmutable $now): array
    {
        $state = $licence->state($now);

        return [
            'id' => $licence->id,
            'status' => $state->status->value,
            'statusLabel' => $state->status->label(),
            'reason' => $state->reason,
            'canTrade' => $state->status->canTrade(),
            'isTrial' => $state->isTrial,
            'endsAt' => LicenceData::date($state->endsAt),
            'graceEndsAt' => LicenceData::date($state->graceEndsAt),
            'activatedAt' => LicenceData::date($licence->activated_at),
            'activateBy' => $state->status === LicenceStatus::Issued ? LicenceData::date($licence->activate_by) : null,
            'lastValidatedAt' => LicenceData::date($licence->last_validated_at ?? $licence->last_check_in_at),
            'appVersion' => $licence->last_app_version,
            'maskedKey' => $licence->maskedKey(),
            'plan' => $licence->plan?->name,
            'features' => self::features($licence),
        ];
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function features(Licence $licence): array
    {
        return $licence->features->map(fn (Feature $feature) => ['value' => $feature->value, 'label' => $feature->label()])->values()->all();
    }
}
