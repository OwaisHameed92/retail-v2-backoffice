<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingMailer;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Mail\Data\PaymentReminderData;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;

/**
 * billing:run step on a manual-collection instance (Pakistan plan P5): payment reminder emails for every unpaid
 * invoice (issued, part paid or overdue), each sent once per invoice:
 *
 * - "due soon" `billing.manual.remind_before_days` (3) days before the due date (or later, while still before it;
 *   never on the day it was issued, which already brought the invoice email);
 * - "due today" on the due date;
 * - "overdue" `billing.manual.remind_after_days` (3) days after it, with the day the account is suspended if it is
 *   still unpaid (`billing.suspend_after_days`, 7, as in the UK).
 *
 * Paying stops them (paid invoices are not open). Cancelled businesses get none. Returns how many invoices were
 * reminded. Does nothing on a Direct Debit (GB) instance.
 */
class SendPaymentReminders
{
    private const COLUMNS = [
        PaymentReminderData::DUE_SOON => 'due_soon_reminded_at',
        PaymentReminderData::DUE_TODAY => 'due_today_reminded_at',
        PaymentReminderData::OVERDUE => 'overdue_reminded_at',
    ];

    public function __construct(
        private readonly BillingMailer $mailer,
        private readonly RecordAudit $audit,
    ) {}

    public function handle(CarbonImmutable $now, ?string $companyId = null): int
    {
        if (! ManualCollection::active()) {
            return 0;
        }

        $today = BillingDates::today($now);
        $before = max(1, (int) config('billing.manual.remind_before_days', 3));
        $after = max(1, (int) config('billing.manual.remind_after_days', 3));
        $sent = 0;

        $due = [
            PaymentReminderData::DUE_SOON => fn (Builder $q) => $q->where('due_date', '>', $today->format('Y-m-d'))
                ->where('due_date', '<=', $today->addDays($before)->format('Y-m-d'))
                ->where('issue_date', '<', $today->format('Y-m-d')),
            PaymentReminderData::DUE_TODAY => fn (Builder $q) => $q->where('due_date', $today->format('Y-m-d')),
            PaymentReminderData::OVERDUE => fn (Builder $q) => $q->where('due_date', '<=', $today->subDays($after)->format('Y-m-d')),
        ];

        foreach ($due as $kind => $when) {
            $invoices = Invoice::withoutCompanyScope()->open()->whereNotNull('number')->whereNull(self::COLUMNS[$kind])
                ->when($companyId !== null, fn (Builder $q) => $q->where('company_id', $companyId))
                ->where($when)->orderBy('due_date')->orderBy('sequence')->get();

            foreach ($invoices as $invoice) {
                $sent += (int) $this->remind($invoice, $kind, $now);
            }
        }

        return $sent;
    }

    /** @param 'dueSoon'|'dueToday'|'overdue' $kind */
    private function remind(Invoice $invoice, string $kind, CarbonImmutable $now): bool
    {
        $company = Company::query()->find($invoice->company_id);
        $invoice->forceFill([self::COLUMNS[$kind] => $now])->save();

        if ($company === null || $company->status === CompanyStatus::Cancelled || $invoice->due_date === null) {
            return false;
        }

        $this->mailer->paymentReminder($invoice, $kind, $kind === PaymentReminderData::OVERDUE ? BillingStatus::invoiceLockDay($invoice) : null);
        $this->audit->handle('invoice.reminder_sent', $invoice, null, null, [
            'number' => $invoice->number,
            'reminder' => $kind,
            'due_date' => $invoice->due_date->format('Y-m-d'),
            'balance' => $invoice->balance,
        ]);

        return true;
    }
}
