<?php

namespace App\Domain\Privacy\Actions;

use App\Domain\Privacy\Models\PrivacySettings;
use App\Domain\Privacy\Queries\RetentionDue;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * Data retention for one business (module 7.7). Dry run (the default): counts the customers past retention and
 * stores the count and time on the settings. With `$apply`: anonymises each of them (AnonymiseCustomer, source
 * `retention`); a customer whose account is not settled is skipped and stays due. A business with no retention
 * period set is left alone.
 *
 * @phpstan-type Outcome array{due: int, anonymised: int, skipped: int}
 */
final class ApplyRetention
{
    public const BATCH = 500;

    public function __construct(
        private readonly CurrentCompany $tenancy,
        private readonly AnonymiseCustomer $anonymise,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return Outcome
     */
    public function handle(Company $company, bool $apply, ?int $userId = null): array
    {
        return $this->tenancy->runAs($company, function (Company $company) use ($apply, $userId): array {
            $settings = PrivacySettings::query()->first();
            $months = $settings?->retention_months;

            if ($settings === null || $months === null) {
                return ['due' => 0, 'anonymised' => 0, 'skipped' => 0];
            }

            $ids = RetentionDue::query($months)->orderBy('id')->limit(self::BATCH)->pluck('id')->all();
            $anonymised = 0;
            $skipped = 0;

            if ($apply) {
                foreach ($ids as $id) {
                    try {
                        $this->anonymise->handle($company, (string) $id, $userId, 'retention', "Past the {$months}-month data retention period.");
                        $anonymised++;
                    } catch (ValidationException) {
                        $skipped++;
                    }
                }

                if ($anonymised > 0) {
                    $this->audit->handle('privacy.retention_applied', null, null, null, ['months' => $months, 'anonymised' => $anonymised, 'skipped' => $skipped], companyId: $company->id);
                }
            }

            $settings->forceFill(['due_count' => RetentionDue::query($months)->count(), 'last_checked_at' => CarbonImmutable::now('UTC')])->save();

            return ['due' => count($ids), 'anonymised' => $anonymised, 'skipped' => $skipped];
        });
    }
}
