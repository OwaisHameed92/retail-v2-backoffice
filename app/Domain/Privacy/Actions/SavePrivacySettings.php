<?php

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Models\PrivacySettings;
use App\Domain\Privacy\Queries\RetentionDue;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Enums\SettingScope;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Saves a business's customer data retention (module 7.7): months after a customer's last activity (null = keep until
 * asked) and whether the daily run anonymises them (off = it only counts them). The period also goes to every till
 * as the shared company setting `compliance.gdpr_retention_months` (contract settings sharedKeys), so the tills
 * keep the same period. Audited.
 */
final class SavePrivacySettings
{
    public const TILL_KEY = 'compliance.gdpr_retention_months';

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly SaveTillSetting $tillSetting,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Company $company, ?int $months, bool $autoAnonymise, ?int $userId): PrivacySettings
    {
        return $this->tenancy->runAs($company, fn (Company $company): PrivacySettings => DB::transaction(function () use ($company, $months, $autoAnonymise, $userId) {
            $settings = PrivacySettings::query()->lockForUpdate()->first() ?? new PrivacySettings(['company_id' => $company->id]);
            $before = ['retentionMonths' => $settings->retention_months, 'autoAnonymise' => (bool) $settings->auto_anonymise];
            $auto = $months !== null && $autoAnonymise;

            $settings->forceFill([
                'retention_months' => $months,
                'auto_anonymise' => $auto,
                'updated_by_user_id' => $userId,
                'due_count' => $months === null ? 0 : RetentionDue::query($months)->count(),
                'last_checked_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $after = ['retentionMonths' => $months, 'autoAnonymise' => $auto];

            if ($before !== $after) {
                if ($before['retentionMonths'] !== $months) {
                    $this->tillSetting->handle($company, SettingScope::Company, null, self::TILL_KEY, $months === null ? null : (string) $months);
                }

                $this->audit->handle('privacy.settings_updated', null, $before, $after, companyId: $company->id);
            }

            return $settings;
        }));
    }
}
