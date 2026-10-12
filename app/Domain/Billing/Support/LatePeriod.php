<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;

/**
 * A period invoice paid after its period ended (audit BILL-7; owner rule in docs/billing-flow.md "Paid late"). Paid by
 * hand (Pakistan, or a UK business not on Direct Debit), its tills had run out and the next invoice is only made from
 * the date they are paid to, so renewing nothing left them locked with nothing to pay. Instead the payment buys one
 * period from the day it is paid, as an invoice for tills that ran out starts today (BillingPeriod::nextStart):
 * November paid on 2 December renews to 1 January, and the next invoice follows from there.
 *
 * Only tills that have run out are renewed this way: a till still paid (the invoice was arrears for time already
 * used) keeps its date. A Direct Debit business is left as before: its subscription invoices every period itself.
 */
final class LatePeriod
{
    /**
     * The new end when `$invoice` is paid late, else null: on time, a setup fee, a Direct Debit business, or no money
     * received on it (written off with a credit note: a waived period buys nothing).
     */
    public static function until(Invoice $invoice, CarbonImmutable $now): ?CarbonImmutable
    {
        if ($invoice->kind !== InvoiceKind::Subscription || BillingDates::endOfDay($invoice->period_end)->greaterThan($now)
            || Money::compare($invoice->amount_paid, '0') <= 0) {
            return null;
        }

        $account = BillingAccount::withoutCompanyScope()->where('company_id', $invoice->company_id)->first();

        if ($account?->isDirectDebit() ?? false) {
            return null;
        }

        return BillingDates::endOfDay($invoice->cycle->periodEnd(BillingDates::today($now)));
    }

    /** A till still paid now: a late invoice does not move it (it paid for time already used). */
    public static function stillPaid(Licence $licence, CarbonImmutable $now): bool
    {
        return $licence->expires_at !== null && $licence->expires_at->greaterThan($now);
    }
}
