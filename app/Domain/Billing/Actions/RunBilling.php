<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\GoCardless\Actions\EnforceDirectDebit;
use Carbon\CarbonImmutable;

/**
 * The daily `billing:run`, in order: create due invoices, mark overdue invoices (and companies), suspend
 * companies unpaid past the grace, send trial emails, then the Direct Debit rules (EnforceDirectDebit). Every
 * step is idempotent, so running it twice a day (or after a missed day) does no harm.
 */
class RunBilling
{
    public function __construct(
        private readonly GenerateDueInvoices $generateDueInvoices,
        private readonly MarkOverdueInvoices $markOverdueInvoices,
        private readonly SuspendForUnpaidInvoices $suspendForUnpaidInvoices,
        private readonly SendTrialEmails $sendTrialEmails,
        private readonly EnforceDirectDebit $enforceDirectDebit,
    ) {}

    /**
     * @return array{invoicesCreated: int, invoicesOverdue: int, companiesSuspended: int, trialReminders: int, trialEnded: int, noMandateSuspended: int, mandateOverdue: int, directDebitReminders: int}
     */
    public function handle(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();

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
            'mandateOverdue' => $directDebit['overdue'],
            'directDebitReminders' => $directDebit['reminders'],
        ];
    }
}
