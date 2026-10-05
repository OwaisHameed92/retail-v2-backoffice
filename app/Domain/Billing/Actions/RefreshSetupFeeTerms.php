<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * billing:run step (owner rules 2026-10-05): ApplySetupFeeTerms for every trading business, so a setup-only plan's
 * new tills get the full licence, full terms are topped up before they run low, and a Direct Debit waiting for the
 * setup fee starts once it is paid. Idempotent. Returns how many licences got a new date.
 */
class RefreshSetupFeeTerms
{
    public function __construct(private readonly ApplySetupFeeTerms $terms) {}

    public function handle(CarbonImmutable $now): int
    {
        $count = 0;

        Company::query()->whereIn('status', [CompanyStatus::Trial->value, CompanyStatus::Active->value, CompanyStatus::Overdue->value])
            ->orderBy('id')->chunkById(200, function ($companies) use (&$count, $now) {
                foreach ($companies as $company) {
                    $count += $this->terms->handle($company, now: $now);
                }
            });

        return $count;
    }
}
