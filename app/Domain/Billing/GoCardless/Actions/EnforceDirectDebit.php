<?php

namespace App\Domain\Billing\GoCardless\Actions;

use App\Domain\Billing\Enums\BillingMode;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\DirectDebitMailer;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Actions\SuspendCompany;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * billing:run step for Direct Debit companies (modules 1.12/1.13, owner rules 2026-10-05):
 *
 * - no working mandate (never set up, or cancelled/failed and not replaced) and something recurring to collect:
 *   one reminder email (the setup link) once less than `mandate_reminder_hours` (48) are left before the deadline;
 *   when the deadline passes, a billing suspension once per deadline ("No Direct Debit set up" / "Direct Debit
 *   cancelled and not replaced"), so the tills lock at their next check-in. A new mandate lifts it at once
 *   (ApplyMandate → ReleaseBillingHolds);
 * - a failed payment still unpaid `dunning_reminder_days` (5) after the failure email: one reminder.
 *
 * `$companyId` limits it to one business (the demo:billing showcase); billing:run passes none.
 *
 * @phpstan-type Result array{suspended: int, mandateReminders: int, reminders: int}
 */
class EnforceDirectDebit
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly SuspendCompany $suspendCompany,
        private readonly DirectDebitMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return Result
     */
    public function handle(CarbonImmutable $now, ?string $companyId = null): array
    {
        $result = ['suspended' => 0, 'mandateReminders' => 0, 'reminders' => 0];

        $accounts = BillingAccount::withoutCompanyScope()->where('billing_mode', BillingMode::DirectDebit->value)
            ->whereNotNull('mandate_deadline_at')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->get()
            ->reject(fn (BillingAccount $account) => $account->hasUsableMandate());

        foreach ($accounts as $account) {
            $company = Company::query()->find($account->company_id);

            if ($company === null || ! in_array($company->status, [CompanyStatus::Trial, CompanyStatus::Active, CompanyStatus::Overdue], true)
                || ! MandateDeadline::applies($company, $account)) {
                continue;
            }

            if (MandateDeadline::missed($company, $account, $now)) {
                $result['suspended'] += (int) $this->suspend($company, $now);
            } else {
                $result['mandateReminders'] += (int) $this->remind($company, $account, $now);
            }
        }

        $result['reminders'] = $this->reminders($now, $companyId);

        return $result;
    }

    private function remind(Company $company, BillingAccount $account, CarbonImmutable $now): bool
    {
        $deadline = $account->mandate_deadline_at;
        $hours = max(1, (int) config('billing.direct_debit.mandate_reminder_hours', 48));

        if ($deadline === null || $deadline->greaterThan($now->addHours($hours))
            || $account->mandate_reminder_for?->getTimestamp() === $deadline->getTimestamp()) {
            return false;
        }

        $account->mandate_reminder_for = $deadline;
        $account->save();
        $this->mailer->setup($company, $account, $deadline, reminder: true);
        $this->audit->handle('billing.dd_mandate_reminder', $account, null, null, ['deadline' => $deadline->toIso8601String()], companyId: $company->id);

        return true;
    }

    private function suspend(Company $company, CarbonImmutable $now): bool
    {
        $suspended = DB::transaction(function () use ($company) {
            $account = $this->accounts->lock($company);
            $deadline = $account->mandate_deadline_at;

            if ($deadline === null || $account->mandate_grace_suspended_for?->getTimestamp() === $deadline->getTimestamp() || $account->hasUsableMandate()) {
                return null;
            }

            $reason = MandateDeadline::reason($account);
            $company = $this->suspendCompany->handle($company, $reason);
            $account->billing_suspended_at = CarbonImmutable::instance($company->suspended_at ?? now());
            $account->mandate_grace_suspended_for = $deadline;
            $account->save();

            $this->audit->handle('billing.dd_no_mandate_suspended', $account, null, null, ['deadline' => $deadline->toIso8601String(), 'reason' => $reason], companyId: $company->id);

            return [$company, $reason];
        });

        if ($suspended !== null) {
            $this->mailer->suspendedWithoutMandate($suspended[0], $suspended[1], $now);
        }

        return $suspended !== null;
    }

    private function reminders(CarbonImmutable $now, ?string $companyId): int
    {
        $days = max(1, (int) config('billing.direct_debit.dunning_reminder_days', 5));
        $sent = 0;

        $rows = GoCardlessPayment::withoutCompanyScope()->with('invoice')
            ->whereIn('status', [PaymentStatus::Failed->value, PaymentStatus::ChargedBack->value])
            ->whereNotNull('failure_notified_at')->where('failure_notified_at', '<=', $now->subDays($days))
            ->whereNull('reminder_sent_at')->when($companyId !== null, fn ($q) => $q->where('company_id', $companyId))->get();

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
