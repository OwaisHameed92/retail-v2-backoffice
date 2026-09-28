<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\DirectDebitMailer;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Licensing\Actions\RenewCompanyLicences;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\MarkCompanyOverdue;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * billing:run step for Direct Debit companies (module 1.12), after the trial emails (which carry the setup link):
 *
 * - trial over, never paid, still no working mandate `mandate_grace_days` (3) after the trial end: suspended
 *   ("No Direct Debit set up after the free trial") as a billing suspension, so setting it up lifts it;
 * - mandate lost more than `mandate_grace_days` ago and not replaced: company marked overdue (once); billing:run
 *   invoices it by hand from then on, and unpaid invoices suspend it as usual;
 * - a failed payment still unpaid `dunning_reminder_days` (5) after the failure email: one reminder.
 *
 * @phpstan-type Result array{suspended: int, overdue: int, reminders: int}
 */
class EnforceDirectDebit
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly SuspendCompany $suspendCompany,
        private readonly MarkCompanyOverdue $markCompanyOverdue,
        private readonly DirectDebitMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return Result
     */
    public function handle(CarbonImmutable $now): array
    {
        $grace = max(0, (int) config('billing.direct_debit.mandate_grace_days', 3));
        $result = ['suspended' => 0, 'overdue' => 0, 'reminders' => 0];

        $accounts = BillingAccount::withoutCompanyScope()->where('billing_mode', BillingMode::DirectDebit->value)->get()
            ->reject(fn (BillingAccount $account) => $account->hasUsableMandate());

        foreach ($accounts as $account) {
            $company = Company::query()->find($account->company_id);

            if ($company === null || ! in_array($company->status, [CompanyStatus::Trial, CompanyStatus::Active, CompanyStatus::Overdue], true)) {
                continue;
            }

            if ($account->gc_mandate_lost_at !== null) {
                $result['overdue'] += (int) $this->lostMandate($company, $account, $now, $grace);
            } else {
                $result['suspended'] += (int) $this->noMandateAfterTrial($company, $now, $grace);
            }
        }

        $result['reminders'] = $this->reminders($now);

        return $result;
    }

    private function lostMandate(Company $company, BillingAccount $account, CarbonImmutable $now, int $grace): bool
    {
        if ($account->mandate_overdue_at !== null || $account->gc_mandate_lost_at?->addDays($grace)->greaterThan($now)) {
            return false;
        }

        $account->mandate_overdue_at = $now;
        $account->save();

        return $this->markCompanyOverdue->handle($company, 'Direct Debit '.mb_strtolower($account->gc_mandate_status?->label() ?? 'cancelled').' and not replaced');
    }

    private function noMandateAfterTrial(Company $company, CarbonImmutable $now, int $grace): bool
    {
        $licences = RenewCompanyLicences::renewable($company)->get();

        if ($licences->contains(fn (Licence $licence) => $licence->expires_at !== null)) {
            return false; // paid time: invoices and the usual overdue rules apply
        }

        $trialEnds = $licences->filter(fn (Licence $licence) => $licence->activated_at !== null && $licence->trial_ends_at !== null)
            ->map(fn (Licence $licence) => $licence->trial_ends_at)->min();

        if ($trialEnds === null || $trialEnds->addDays($grace)->greaterThan($now)) {
            return false;
        }

        $reason = 'No Direct Debit set up after the free trial';

        $suspended = DB::transaction(function () use ($company, $trialEnds, $reason) {
            $account = $this->accounts->lock($company);

            if ($account->mandate_grace_suspended_for?->getTimestamp() === $trialEnds->getTimestamp() || $account->hasUsableMandate()) {
                return null;
            }

            $company = $this->suspendCompany->handle($company, $reason);
            $account->billing_suspended_at = CarbonImmutable::instance($company->suspended_at ?? now());
            $account->mandate_grace_suspended_for = $trialEnds;
            $account->save();

            $this->audit->handle('billing.dd_no_mandate_suspended', $account, null, null, ['trial_ends_at' => $trialEnds->toIso8601String()], companyId: $company->id);

            return $company;
        });

        if ($suspended !== null) {
            $this->mailer->suspendedWithoutMandate($suspended, $reason, $now);
        }

        return $suspended !== null;
    }

    private function reminders(CarbonImmutable $now): int
    {
        $days = max(1, (int) config('billing.direct_debit.dunning_reminder_days', 5));
        $sent = 0;

        $rows = GoCardlessPayment::withoutCompanyScope()->with('invoice')
            ->whereIn('status', [PaymentStatus::Failed->value, PaymentStatus::ChargedBack->value])
            ->whereNotNull('failure_notified_at')->where('failure_notified_at', '<=', $now->subDays($days))
            ->whereNull('reminder_sent_at')->get();

        foreach ($rows as $row) {
            $company = Company::query()->find($row->company_id);
            $row->reminder_sent_at = $now;
            $row->save();

            if ($company === null || $row->invoice === null || ! $row->invoice->isOpen() || $company->status === CompanyStatus::Cancelled) {
                continue;
            }

            $this->mailer->failed($company, $row, reminder: true);
            $sent++;
        }

        return $sent;
    }
}
