<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceGuard;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Manual collection (Pakistan plan P5), the counterpart of the UK's "Direct Debit starts after the setup fee": when
 * the setup fee (or its first instalment) is paid, the first period invoice is issued at once if the tills run out
 * within `billing.generate.days_before` days or already did (otherwise billing:run issues it in time). When the trial
 * is already over (or ends before that invoice is due), trial tills get their trial moved to the end of its due date,
 * so recording the setup fee unlocks them now and the usual overdue and suspension rules take over (as the UK's
 * bridgeTrial does up to the first collection). Never shortens a date. Returns how many licences moved.
 */
class StartManualBilling
{
    public function __construct(
        private readonly IssueManualInvoices $issueInvoices,
        private readonly BillingAccounts $accounts,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(Company $company, ?CarbonImmutable $now = null): int
    {
        $now ??= CarbonImmutable::now();
        $invoice = $this->issueInvoices->issueNext($company, $now);

        if ($invoice === null || $invoice->due_date === null) {
            return 0;
        }

        $until = BillingDates::endOfDay($invoice->due_date);

        return DB::transaction(function () use ($company, $until, $now, $invoice) {
            $this->accounts->lock($company);
            $moved = 0;

            // P11: a till waiting for its added-till setup fee is not moved.
            foreach (RenewCompanyLicences::renewable($company)->whereNotIn('id', SetupFeeTills::heldLicenceIds($company->id))->get() as $licence) {
                /** @var Licence $licence */
                if ($licence->expires_at !== null || $licence->activated_at === null || ($licence->trial_ends_at !== null && $licence->trial_ends_at->greaterThanOrEqualTo($until))) {
                    continue;
                }

                $locked = LicenceGuard::lock($licence);
                $before = ['trial_ends_at' => $locked->trial_ends_at?->toIso8601String(), 'status' => $locked->status->value];
                $locked->trial_ends_at = $until;
                $locked->status = LicenceTerms::storedStatusAfterDateChange($locked, $now);
                $locked->save();
                $moved++;

                $this->audit->handle('licence.trial_extended', $locked, $before, ['trial_ends_at' => $until->toIso8601String(), 'status' => $locked->status->value], [
                    'reason' => 'Setup fee paid: first invoice '.$invoice->number.' due',
                ], companyId: $company->id);
            }

            return $moved;
        });
    }
}
