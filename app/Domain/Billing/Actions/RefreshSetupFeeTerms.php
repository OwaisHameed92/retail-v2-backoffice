<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Shared\Support\BusinessFailures;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * billing:run step (owner rules 2026-10-05): ApplySetupFeeTerms for every trading business, so a setup-only plan's
 * new tills get the full licence, full terms are topped up before they run low, and a Direct Debit waiting for the
 * setup fee starts once it is paid. Idempotent. Returns how many licences got a new date. One business's error is
 * reported and the rest still run (BusinessFailures).
 */
class RefreshSetupFeeTerms
{
    public const TASK = 'billing:run setup-fee-terms';

    public function __construct(
        private readonly ApplySetupFeeTerms $terms,
        private readonly BusinessFailures $failures,
    ) {}

    public function handle(CarbonImmutable $now): int
    {
        $count = 0;

        Company::query()->whereIn('status', [CompanyStatus::Trial->value, CompanyStatus::Active->value, CompanyStatus::Overdue->value])
            ->orderBy('id')->chunkById(200, function ($companies) use (&$count, $now) {
                foreach ($companies as $company) {
                    $count += $this->failures->attempt(self::TASK, $company->id, fn () => $this->terms->handle($company, now: $now), 0);
                }
            });

        return $count;
    }
}
