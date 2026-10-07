<?php

namespace App\Domain\Billing\Actions;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\Actor;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Billing\Support\SetupFeeTills;
use App\Domain\Billing\Support\Vat;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Tills added to a business after its setup fee (P11, owner 2026-10-07). Called by the admin "Add till" and "Add
 * branch" flows (also how a till request is approved) with the licences just issued.
 *
 * - The first setup fee is not invoiced or recorded yet: nothing; it will cover these tills (a per-till plan counts
 *   them).
 * - Per business plan (as before): no charge; the tills are marked covered so a later switch to per till never
 *   charges them.
 * - Per till plan: the tills not covered yet (never more than the ones just added: tills a business already had are
 *   grandfathered) get one "Setup fee (added tills)" invoice, a line per till, amount = the per-till fee (the
 *   business's own, else the plan's) × tills unless the admin entered another total; 0 waives it. The invoice is
 *   issued and emailed like any invoice and paid by hand like the first setup fee (Record payment). Until it is paid
 *   those tills stay on their trial (SetupFeeTills::heldLicenceIds); paying it unlocks them.
 */
class ChargeAddedTills
{
    public function __construct(
        private readonly BillingAccounts $accounts,
        private readonly IssueInvoice $issueInvoice,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  list<Licence>  $licences  The licences of the tills just added.
     * @param  string|null  $amount  Net total the admin entered; null = the per-till fee × tills; "0" waives it.
     *
     * @throws ValidationException
     */
    public function handle(Company $company, array $licences, ?string $amount = null): ?Invoice
    {
        // Only this business's own tills (never another company's licence).
        $licences = array_values(array_filter($licences, fn (Licence $licence) => $licence->company_id === $company->id));

        if ($licences === [] || $company->isCancelled() || $company->trashed()) {
            return null;
        }

        if ($amount !== null && Money::isNegative($amount)) {
            throw ValidationException::withMessages(['till_setup_fee' => 'The setup fee cannot be below 0.']);
        }

        $peek = $this->accounts->for($company);

        if ($peek->setup_fee_invoiced_at === null && $peek->upfront_recorded_at === null) {
            return null; // no setup fee yet (or never one): nothing to cover, no billing row made
        }

        $draft = DB::transaction(function () use ($company, $licences, $amount): ?Invoice {
            $account = $this->accounts->lock($company);

            if ($account->setup_fee_invoiced_at === null && $account->upfront_recorded_at === null) {
                return null;
            }

            $tills = SetupFeeTills::count($company);
            $covered = $account->setup_fee_covered_tills ?? max(0, $tills - count($licences));
            $chargeable = SetupFeeTills::perTill($company) ? min(count($licences), max(0, $tills - $covered)) : 0;
            $account->setup_fee_covered_tills = max($covered, $tills);
            $account->save();

            if ($chargeable === 0) {
                return null;
            }

            $charged = array_slice($licences, -$chargeable);
            $net = $amount !== null ? Money::normalise($amount) : Money::mul(SetupFee::perTillFee($company, $account), $chargeable);

            if (Money::isZero($net)) {
                $this->audit->handle('billing.till_setup_fee_waived', $account, null, ['tills' => $chargeable], [
                    'registers' => implode(', ', array_map(fn (Licence $licence) => $licence->register_id, $charged)),
                ], companyId: $company->id);

                return null;
            }

            $invoice = $this->draft($company, $account, $charged, $net);

            $this->audit->handle('billing.till_setup_fee_invoiced', $account, null, [
                'tills' => $chargeable,
                'net' => $net,
            ], ['amount_label' => BillingFormat::money($net), 'covered_tills' => $account->setup_fee_covered_tills], companyId: $company->id);

            return $invoice;
        });

        return $draft === null ? null : $this->issueInvoice->handle($draft, send: true);
    }

    /**
     * One draft invoice with a line per till; the net is split evenly (the last line takes the pennies left).
     *
     * @param  list<Licence>  $licences
     */
    private function draft(Company $company, BillingAccount $account, array $licences, string $net): Invoice
    {
        $today = BillingDates::today();
        $vatRate = Vat::rateFor($account);
        $count = count($licences);
        $each = Money::round(bcdiv(Money::parse($net), (string) $count, 6));
        $left = $net;
        $lines = [];

        foreach ($licences as $i => $licence) {
            $lineNet = $i === $count - 1 ? $left : $each;
            $left = Money::sub($left, $lineNet);
            $vat = InvoiceMaths::vatOn($lineNet, $vatRate);
            $lines[] = ['licence' => $licence, 'net' => $lineNet, 'vat' => $vat, 'gross' => Money::add($lineNet, $vat)];
        }

        $totals = InvoiceMaths::totals($lines);

        $invoice = new Invoice([
            'status' => InvoiceStatus::Draft,
            'kind' => InvoiceKind::TillSetupFee,
            'cycle' => $account->cycle,
            'period_start' => $today,
            'period_end' => $today,
            'vat_rate' => $vatRate,
            'subtotal' => $totals['subtotal'],
            'vat_total' => $totals['vat_total'],
            'total' => $totals['total'],
            'balance' => $totals['total'],
            'created_by_admin_id' => Actor::adminId(),
        ]);
        $invoice->company_id = $company->id;
        $invoice->save();

        foreach ($lines as $position => $line) {
            /** @var Licence $licence */
            $licence = $line['licence'];
            $licence->loadMissing(['branch', 'register']);

            $row = new InvoiceLine([
                'position' => $position + 1,
                'description' => 'Setup fee · '.($licence->register->name ?? 'Till').' ('.($licence->branch->name ?? 'Branch').')',
                'quantity' => '1.0000',
                'unit_price' => $line['net'],
                'net' => $line['net'],
                'vat' => $line['vat'],
                'gross' => $line['gross'],
                'licence_id' => $licence->id,
                'register_id' => $licence->register_id,
                'plan_id' => $licence->plan_id,
            ]);
            $row->company_id = $company->id;
            $row->invoice_id = $invoice->id;
            $row->save();
        }

        return $invoice;
    }
}
