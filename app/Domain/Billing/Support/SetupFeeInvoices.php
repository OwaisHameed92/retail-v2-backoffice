<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;

/**
 * Draft setup fee invoices made outside the first setup fee (ChargeSetupFee): the tills added later (P11,
 * ChargeAddedTills) and a plan change (ChangeBusinessPlan). The caller holds the billing lock and issues them.
 */
final class SetupFeeInvoices
{
    /**
     * A "Setup fee (added tills)" draft with a line per till; the net is split evenly (the last line takes the pennies
     * left). Until it is paid those tills are held on their trial (SetupFeeTills::heldLicenceIds).
     *
     * @param  list<Licence>  $licences
     */
    public static function tillsDraft(Company $company, BillingAccount $account, array $licences, string $net): Invoice
    {
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

        $invoice = self::header($company, $account, InvoiceKind::TillSetupFee, InvoiceMaths::totals($lines), $vatRate);

        foreach ($lines as $position => $line) {
            /** @var Licence $licence */
            $licence = $line['licence'];
            $licence->loadMissing(['branch', 'register']);

            self::line($invoice, [
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
        }

        return $invoice;
    }

    /** The business's own setup fee as one "Setup fee" draft (one line), e.g. on a change to a plan with a setup fee. */
    public static function businessDraft(Company $company, BillingAccount $account, string $net, string $description): Invoice
    {
        $vatRate = Vat::rateFor($account);
        $vat = InvoiceMaths::vatOn($net, $vatRate);
        $amounts = ['net' => $net, 'vat' => $vat, 'gross' => Money::add($net, $vat)];
        $invoice = self::header($company, $account, InvoiceKind::SetupFee, InvoiceMaths::totals([$amounts]), $vatRate);

        self::line($invoice, [
            'position' => 1,
            'description' => mb_substr($description, 0, 255),
            'quantity' => '1.0000',
            'unit_price' => $net,
            ...$amounts,
        ]);

        return $invoice;
    }

    /**
     * @param  array{subtotal: string, vat_total: string, total: string}  $totals
     */
    private static function header(Company $company, BillingAccount $account, InvoiceKind $kind, array $totals, string $vatRate): Invoice
    {
        $today = BillingDates::today();

        $invoice = new Invoice([
            'status' => InvoiceStatus::Draft,
            'kind' => $kind,
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

        return $invoice;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private static function line(Invoice $invoice, array $attributes): void
    {
        $row = new InvoiceLine($attributes);
        $row->company_id = $invoice->company_id;
        $row->invoice_id = $invoice->id;
        $row->save();
    }
}
