<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Actions\ApplySetupFeeTerms;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Audit BILL-6 (2026-10-11): a setup fee invoice (the business's, or an added till's) settled by a credit note or by
 * credit (Apply credit, credit taken when an invoice is issued) unlocks what it held back at once, as Record payment
 * does: ApplySetupFeeTerms with `justPaid` (setup-only licence, the Direct Debit start and bridgeTrial, Pakistan's
 * first period invoice). Runs once the outermost transaction commits, so it never runs for a rolled-back settlement
 * and GoCardless is never called with the billing lock held. ApplySetupFeeTerms is safe to run again.
 */
final class SetupFeeUnlock
{
    /** Call right after SettleInvoice with the invoices it may have settled; does nothing unless one is a paid setup fee. */
    public static function afterSettled(Company $company, Invoice ...$invoices): void
    {
        foreach ($invoices as $invoice) {
            if ($invoice->kind->isSetupFee() && $invoice->status === InvoiceStatus::Paid) {
                DB::afterCommit(function () use ($company) {
                    $fresh = $company->fresh();

                    if ($fresh !== null) {
                        app(ApplySetupFeeTerms::class)->handle($fresh, justPaid: true);
                    }
                });

                return;
            }
        }
    }
}
