<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\Actions\IssueMissingLicences;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * The licence side of the admin tenant page: the Licences tab and the licence status of each till in the
 * Branches tab.
 */
final class TenantLicences
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Company $company, CarbonImmutable $now): array
    {
        $licences = Licence::withoutCompanyScope()
            ->whereBelongsTo($company)
            ->with(['company', 'branch', 'register', 'plan'])
            ->get()
            ->sortBy(fn (Licence $licence) => [
                $licence->isRevoked() ? 1 : 0,
                $licence->branch->name ?? '',
                $licence->register->code ?? '',
                $licence->created_at?->getTimestamp() ?? 0,
            ])
            ->values();

        $counts = array_fill_keys(array_map(fn (LicenceStatus $s) => $s->value, LicenceStatus::cases()), 0);
        $tills = [];

        foreach ($licences as $licence) {
            $state = LicenceState::for($licence, $now);
            $counts[$state->status->value]++;

            if (! $licence->isRevoked()) {
                $tills[$licence->register_id] = [
                    'id' => $licence->id,
                    'status' => $state->status->value,
                    'statusLabel' => $state->status->label(),
                    'maskedKey' => $licence->maskedKey(),
                    'keyLast4' => $licence->key_last4,
                    'deviceName' => $licence->device_name,
                ];
            }
        }

        $renewable = RenewCompanyLicences::renewable($company)->get();
        $plan = DefaultPlan::for($company);

        return [
            'licences' => $licences->map(fn (Licence $licence) => LicenceData::row($licence, $now))->all(),
            'tillLicences' => (object) $tills,
            'summary' => [
                'counts' => $counts,
                'live' => $licences->reject(fn (Licence $licence) => $licence->isRevoked())->count(),
                'missing' => IssueMissingLicences::countUnlicensed($company),
                'renewable' => $renewable->count(),
                // Licence API alerts not yet resolved (module 1.5).
                'openAlerts' => LicenceAlerts::openCount($company->id),
                'latestEnd' => LicenceData::date($renewable->map(fn (Licence $licence) => LicenceTerms::endsAt($licence))->filter()->max()),
            ],
            'plan' => [
                'current' => LicenceData::plan($plan),
                'isCompanyPlan' => $plan !== null && $company->plan_id === $plan->id,
            ],
        ];
    }
}
