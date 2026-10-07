<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\SetupFeeState;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * P11 backfill, run once on each server after the deploy (`billing:backfill-setup-fee-coverage`): every business
 * whose setup fee is settled (paid in full, or recorded as nothing to pay) has its tills marked covered, so a switch
 * to a per-till setup fee never charges a till it already paid for (e.g. a business that paid one £1,200 setup fee
 * for 2 tills covers those 2). Covered tills = the tills it has now; never lowered. Idempotent: a second run changes
 * nothing. Audited per business changed (`billing.setup_fee_coverage_backfilled`).
 */
class BackfillSetupFeeCoverage
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return list<array{company: string, name: string, before: int|null, after: int}>
     */
    public function handle(bool $dryRun = false): array
    {
        $changes = [];

        BillingAccount::withoutCompanyScope()->with('company')->orderBy('id')->chunkById(200, function ($accounts) use (&$changes, $dryRun) {
            foreach ($accounts as $account) {
                /** @var BillingAccount $account */
                $company = $account->company;

                if ($company === null || $company->trashed() || ! $this->settled($company, $account)) {
                    continue;
                }

                $tills = SetupFeeTills::count($company);

                if ($account->setup_fee_covered_tills !== null && $account->setup_fee_covered_tills >= $tills) {
                    continue;
                }

                $changes[] = ['company' => $company->id, 'name' => $company->name, 'before' => $account->setup_fee_covered_tills, 'after' => $tills];

                if (! $dryRun) {
                    $this->apply($company, $tills);
                }
            }
        });

        return $changes;
    }

    private function settled(Company $company, BillingAccount $account): bool
    {
        $state = SetupFeeState::for($company, $account);

        return $state->status === SetupFeeState::PAID
            || ($state->status === SetupFeeState::NONE && ($account->upfront_recorded_at !== null || $account->setup_fee_invoiced_at !== null));
    }

    private function apply(Company $company, int $tills): void
    {
        DB::transaction(function () use ($company, $tills) {
            $account = $this->accounts->lock($company);
            $before = $account->setup_fee_covered_tills;
            $account->setup_fee_covered_tills = max((int) ($before ?? 0), $tills);
            $account->save();

            $this->audit->handle('billing.setup_fee_coverage_backfilled', $account, ['setup_fee_covered_tills' => $before], [
                'setup_fee_covered_tills' => $account->setup_fee_covered_tills,
            ], companyId: $company->id);
        });
    }
}
