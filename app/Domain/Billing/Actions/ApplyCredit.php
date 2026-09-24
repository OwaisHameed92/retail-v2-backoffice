<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\Allocator;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Uses a company's unallocated credit (the part of earlier payments not put towards any invoice) on its open
 * invoices, oldest payment first onto the oldest invoice first (or onto one invoice). Invoices that end up paid
 * are settled (licences renewed). IssueInvoice calls it for every new invoice.
 */
class ApplyCredit
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly Allocator $allocator,
        private readonly SettleInvoice $settleInvoice,
        private readonly ReleaseBillingHolds $releaseHolds,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * Staff action ("Apply credit"). Returns the amount applied.
     *
     * @throws ValidationException
     */
    public function handle(Company $company): string
    {
        $applied = DB::transaction(function () use ($company) {
            $this->accounts->lock($company);

            return $this->applyLocked($company, null, CarbonImmutable::now());
        });

        if (Money::isZero($applied)) {
            throw ValidationException::withMessages(['status' => "{$company->name} has no credit to apply to an open invoice."]);
        }

        $this->releaseHolds->handle($company);

        return $applied;
    }

    /**
     * With the billing lock already held. Returns the amount applied ("0.00" when none).
     */
    public function applyLocked(Company $company, ?Invoice $only, CarbonImmutable $now): string
    {
        $payments = Payment::withoutCompanyScope()->where('company_id', $company->id)->where('unallocated', '>', 0)
            ->orderBy('received_at')->orderBy('sequence')->lockForUpdate()->get();

        if ($payments->isEmpty()) {
            return '0.00';
        }

        $invoices = $only !== null
            ? collect([$only])
            : Invoice::withoutCompanyScope()->where('company_id', $company->id)->open()
                ->orderBy('due_date')->orderBy('sequence')->lockForUpdate()->get();

        $applied = [];

        foreach ($invoices as $invoice) {
            foreach ($payments as $payment) {
                if (! $invoice->isOpen() || Money::isZero($invoice->balance)) {
                    break;
                }

                $allocation = $this->allocator->allocate($payment, $invoice);

                if ($allocation !== null) {
                    $applied[] = $allocation->amount;
                }
            }

            if ($invoice->isOpen() && Money::isZero($invoice->balance)) {
                $this->settleInvoice->handle($invoice, $now);
            }
        }

        $total = Money::sum($applied);

        if (! Money::isZero($total)) {
            $this->audit->handle('billing.credit_applied', $company, null, null, ['amount' => $total], companyId: $company->id);
        }

        return $total;
    }
}
