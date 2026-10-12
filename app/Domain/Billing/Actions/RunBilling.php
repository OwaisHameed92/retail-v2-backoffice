<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\GoCardless\Actions\EnforceDirectDebit;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Shared\Support\BusinessFailures;
use Carbon\CarbonImmutable;

/**
 * The daily `billing:run`, in order: setup fee terms (setup-only licences, Direct Debit waiting for the setup fee),
 * create due invoices, mark overdue invoices (and companies), suspend
 * companies unpaid past the grace, send trial emails, then the Direct Debit rules (EnforceDirectDebit). Every
 * step is idempotent, so running it twice a day (or after a missed day) does no harm.
 *
 * Manual collection (Pakistan plan P5): period invoices are issued by IssueManualInvoices instead of
 * GenerateDueInvoices, the Direct Debit step is skipped (there is none), and SendPaymentReminders emails before, on
 * and after each due date (`paymentReminders`, a key only this instance returns).
 *
 * Each step isolates its businesses (audit DATA-7): one business's error is reported with its company id and every
 * other business and later step still runs; `failedCompanyIds()` lists them afterwards (billing:run exits non-zero).
 */
class RunBilling
{
    /** The task prefix of every step's BusinessFailures entries ("billing:run overdue", …). */
    public const TASK = 'billing:run';

    public function __construct(
        private readonly RefreshSetupFeeTerms $refreshSetupFeeTerms,
        private readonly GenerateDueInvoices $generateDueInvoices,
        private readonly MarkOverdueInvoices $markOverdueInvoices,
        private readonly SuspendForUnpaidInvoices $suspendForUnpaidInvoices,
        private readonly SendTrialEmails $sendTrialEmails,
        private readonly EnforceDirectDebit $enforceDirectDebit,
        private readonly IssueManualInvoices $issueManualInvoices,
        private readonly SendPaymentReminders $sendPaymentReminders,
        private readonly BusinessFailures $failures,
    ) {}

    /**
     * @return array{invoicesCreated: int, invoicesOverdue: int, companiesSuspended: int, trialReminders: int, trialEnded: int, noMandateSuspended: int, mandateReminders: int, directDebitReminders: int, setupOnlyLicences: int, paymentReminders?: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $this->failures->forget(self::TASK);

        if (ManualCollection::active()) {
            return $this->manual($now);
        }

        $setupOnly = $this->refreshSetupFeeTerms->handle($now);
        $created = $this->generateDueInvoices->handle($now);
        $overdue = $this->markOverdueInvoices->handle($now);
        $suspended = $this->suspendForUnpaidInvoices->handle($now);
        $trial = $this->sendTrialEmails->handle($now);
        $directDebit = $this->enforceDirectDebit->handle($now);

        return [
            'invoicesCreated' => count($created),
            'invoicesOverdue' => $overdue,
            'companiesSuspended' => $suspended,
            'trialReminders' => $trial['reminders'],
            'trialEnded' => $trial['ended'],
            'noMandateSuspended' => $directDebit['suspended'],
            'mandateReminders' => $directDebit['mandateReminders'],
            'directDebitReminders' => $directDebit['reminders'],
            'setupOnlyLicences' => $setupOnly,
        ];
    }

    /**
     * The businesses a step failed for in the last run (each reported already), in the order they failed.
     *
     * @return list<string>
     */
    public function failedCompanyIds(): array
    {
        return $this->failures->companyIds(self::TASK);
    }

    /**
     * Pakistan plan P5: the same steps without Direct Debit, plus the payment reminders.
     *
     * @return array{invoicesCreated: int, invoicesOverdue: int, companiesSuspended: int, trialReminders: int, trialEnded: int, noMandateSuspended: int, mandateReminders: int, directDebitReminders: int, setupOnlyLicences: int, paymentReminders: int}
     */
    private function manual(CarbonImmutable $now): array
    {
        $setupOnly = $this->refreshSetupFeeTerms->handle($now);
        $created = $this->issueManualInvoices->handle($now);
        $overdue = $this->markOverdueInvoices->handle($now);
        $suspended = $this->suspendForUnpaidInvoices->handle($now);
        $trial = $this->sendTrialEmails->handle($now);
        $reminders = $this->sendPaymentReminders->handle($now);

        return [
            'invoicesCreated' => count($created),
            'invoicesOverdue' => $overdue,
            'companiesSuspended' => $suspended,
            'trialReminders' => $trial['reminders'],
            'trialEnded' => $trial['ended'],
            'noMandateSuspended' => 0,
            'mandateReminders' => 0,
            'directDebitReminders' => 0,
            'setupOnlyLicences' => $setupOnly,
            'paymentReminders' => $reminders,
        ];
    }
}
