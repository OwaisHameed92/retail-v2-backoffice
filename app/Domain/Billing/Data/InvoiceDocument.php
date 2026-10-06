<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Support\BillingAccounts;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Billing\Support\Vat;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\Money;

/**
 * Everything printed on an invoice, already formatted: the admin preview (React) and the PDF render the same
 * document. Issued invoices use their billed-to snapshot; drafts show the current billing settings.
 */
final class InvoiceDocument
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Invoice $invoice): array
    {
        $invoice->loadMissing(['company', 'lines', 'allocations.payment', 'creditNotes']);
        $company = $invoice->company;
        $billTo = self::billTo($invoice);
        $hasVat = ! Money::isZero($invoice->vat_rate);

        return [
            'type' => 'invoice',
            'number' => $invoice->number,
            'title' => $invoice->number ?? 'Draft invoice',
            'status' => $invoice->status->value,
            'statusLabel' => $invoice->status->label(),
            'issueDate' => $invoice->issue_date !== null ? BillingDates::long($invoice->issue_date) : null,
            'dueDate' => $invoice->due_date !== null ? BillingDates::long($invoice->due_date) : null,
            'period' => BillingDates::range($invoice->period_start, $invoice->period_end),
            'cycle' => $invoice->cycle->label(),
            'seller' => self::seller($invoice->seller_vat_number ?? ($invoice->isDraft() ? Vat::number() : null)),
            'billTo' => $billTo + ['companyNumber' => $company?->company_number, 'vatNumber' => $company?->vat_number],
            'hasVat' => $hasVat,
            'vatRate' => BillingFormat::percent($invoice->vat_rate),
            'lines' => $invoice->lines->map(fn (InvoiceLine $line) => [
                'id' => $line->id,
                'description' => $line->description,
                'quantity' => BillingFormat::quantity($line->quantity),
                'unitPrice' => BillingFormat::money($line->unit_price),
                'net' => BillingFormat::money($line->net),
                'vat' => BillingFormat::money($line->vat),
                'gross' => BillingFormat::money($line->gross),
            ])->values()->all(),
            'subtotal' => BillingFormat::money($invoice->subtotal),
            'vatTotal' => BillingFormat::money($invoice->vat_total),
            'total' => BillingFormat::money($invoice->total),
            'amountPaid' => BillingFormat::money($invoice->amount_paid),
            'amountCredited' => BillingFormat::money($invoice->amount_credited),
            'balance' => BillingFormat::money($invoice->balance),
            'hasPayments' => ! Money::isZero($invoice->amount_paid),
            'hasCredits' => ! Money::isZero($invoice->amount_credited),
            'payments' => $invoice->allocations->sortBy(fn (PaymentAllocation $a) => $a->payment?->received_at)->map(fn (PaymentAllocation $allocation) => [
                'number' => $allocation->payment?->number,
                'date' => $allocation->payment !== null ? BillingDates::long(BillingDates::localDate($allocation->payment->received_at)) : null,
                'method' => $allocation->payment?->method->label(),
                'amount' => BillingFormat::money($allocation->amount),
            ])->values()->all(),
            'creditNotes' => $invoice->creditNotes->map(fn (CreditNote $note) => [
                'number' => $note->number,
                'date' => BillingDates::long(BillingDates::localDate($note->issued_at)),
                'reason' => $note->reason,
                'total' => BillingFormat::money($note->total),
            ])->values()->all(),
            'notes' => $invoice->notes,
            'paidOn' => $invoice->paid_at !== null ? BillingDates::long(BillingDates::localDate($invoice->paid_at)) : null,
            'voidedOn' => $invoice->voided_at !== null ? BillingDates::long(BillingDates::localDate($invoice->voided_at)) : null,
            'voidReason' => $invoice->void_reason,
            'bank' => self::bankLines(),
            'reference' => $invoice->number,
            // Pakistan plan P5 (manual collection): "Pay by bank transfer, JazzCash, Easypaisa or cash, quoting …".
            ...(ManualCollection::active() ? ['howToPay' => ManualCollection::howToPay($invoice->number)] : []),
        ];
    }

    /**
     * @return array{name: string, address: list<string>, emails: list<string>}
     */
    public static function billTo(Invoice $invoice): array
    {
        if ($invoice->bill_to_name !== null) {
            return [
                'name' => $invoice->bill_to_name,
                'address' => self::lines($invoice->bill_to_address),
                'emails' => $invoice->bill_to_emails ?? [],
            ];
        }

        $company = $invoice->company;
        $account = $company !== null ? app(BillingAccounts::class)->for($company) : null;

        return [
            'name' => $account?->billing_name ?: ($company->legal_name ?? $company->name ?? 'Customer'),
            'address' => self::lines($account?->billing_address ?: $company?->address),
            'emails' => $account?->emails() ?? [],
        ];
    }

    /**
     * @return array{name: string, legalName: string, address: list<string>, companyNumber: string|null, vatNumber: string|null, email: string|null, phone: string|null, strn?: string|null}
     */
    public static function seller(?string $vatNumber): array
    {
        $blank = fn (mixed $value) => trim((string) $value) === '' ? null : trim((string) $value);

        if (! app(Country::class)->is(Country::DEFAULT)) {
            return self::localSeller($vatNumber, $blank);
        }

        return [
            'name' => (string) config('billing.seller.name', 'Switch & Save'),
            'legalName' => (string) config('billing.seller.legal_name', 'Switch & Save Ltd'),
            'address' => self::lines((string) config('billing.seller.address', '')),
            'companyNumber' => $blank(config('billing.seller.company_number')),
            'vatNumber' => $blank($vatNumber),
            'email' => $blank(config('billing.seller.email')),
            'phone' => $blank(config('billing.seller.phone')),
        ];
    }

    /**
     * Pakistan plan P5 (owner 2026-10-06): off GB the instance presents as a local business, not the UK company. Only
     * the BILLING_SELLER_* values that are set show; the trading name ("Switch & Save") is the least there is and stands
     * in for an unset legal name. The NTN is the frozen tax number, else BILLING_VAT_NUMBER or BILLING_SELLER_NTN; the STRN is
     * BILLING_SELLER_STRN. Empty values are null, so their lines are hidden.
     *
     * @param  callable(mixed): (string|null)  $blank
     * @return array{name: string, legalName: string, address: list<string>, companyNumber: string|null, vatNumber: string|null, email: string|null, phone: string|null, strn: string|null}
     */
    private static function localSeller(?string $vatNumber, callable $blank): array
    {
        $name = $blank(config('billing.seller.name')) ?? 'Switch & Save';

        return [
            'name' => $name,
            'legalName' => $blank(config('billing.seller.legal_name')) ?? $name,
            'address' => self::lines((string) config('billing.seller.address', '')),
            'companyNumber' => $blank(config('billing.seller.company_number')),
            // Our NTN is a registration, shown whether or not GST is charged: frozen on the invoice, else the settings.
            'vatNumber' => $blank($vatNumber) ?? $blank(config('billing.vat.number')) ?? $blank(config('billing.seller.ntn')),
            'email' => $blank(config('billing.seller.email')),
            'phone' => $blank(config('billing.seller.phone')),
            'strn' => $blank(config('billing.seller.strn')),
        ];
    }

    /**
     * "Account name", "Sort code 12-34-56", "Account 12345678": empty when bank details are not configured.
     *
     * @return list<string>
     */
    public static function bankLines(): array
    {
        // Pakistan plan P5: bank name, account title, IBAN and the JazzCash / Easypaisa accounts (BILLING_PAY_*).
        if (ManualCollection::active()) {
            return ManualCollection::payLines();
        }

        $name = trim((string) config('billing.bank.account_name', ''));
        $sortCode = trim((string) config('billing.bank.sort_code', ''));
        $account = trim((string) config('billing.bank.account_number', ''));

        if ($sortCode === '' || $account === '') {
            return [];
        }

        return array_values(array_filter([$name, "Sort code {$sortCode}", "Account {$account}"], fn (string $line) => $line !== ''));
    }

    /**
     * An address split into lines (newlines or commas).
     *
     * @return list<string>
     */
    public static function lines(?string $text): array
    {
        $text = trim((string) $text);

        if ($text === '') {
            return [];
        }

        $parts = preg_split(str_contains($text, "\n") ? '/\r?\n/' : '/,\s*/', $text) ?: [];

        return array_values(array_filter(array_map('trim', $parts), fn (string $line) => $line !== ''));
    }
}
