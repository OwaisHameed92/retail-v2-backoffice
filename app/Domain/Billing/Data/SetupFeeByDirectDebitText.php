<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\BillingStatus;
use Carbon\CarbonImmutable;

/**
 * The billing status card for an unpaid setup fee collected by Direct Debit (the per-business exception, owner
 * 2026-11-05), once it is invoiced. Owner 2026-11-11 (Asia Halal Food Stores): the generic "Setup fee unpaid — tills
 * locked … paid by cash, card or bank transfer" was wrong there: nothing is locked and the money comes from the mandate.
 */
final class SetupFeeByDirectDebitText
{
    /** The first unpaid part when this business's setup fee is invoiced and collected by Direct Debit, else null. */
    public static function firstPart(BillingStatus $status): ?Invoice
    {
        return $status->state === BillingStatus::SETUP_FEE_DUE && $status->account->setup_fee_by_direct_debit
            ? $status->unpaid
            : null;
    }

    public static function headline(Invoice $first): string
    {
        return 'Setup fee by Direct Debit — first payment '.BillingFormat::money($first->balance).' on '.self::day($first);
    }

    /**
     * @return array{text: string, date: string|null}
     */
    public static function next(BillingStatus $status, Invoice $first, bool $portal): array
    {
        $amount = BillingFormat::money($first->balance);
        $day = self::day($first);
        $monthly = $status->recurs ? ' The monthly fee starts once it is paid.' : '';

        if (! $status->account->hasUsableMandate()) {
            return ['text' => $portal
                ? "Set up your Direct Debit so the first payment ({$amount}) can be collected.{$monthly}"
                : "Nothing is collected until the owner sets up the Direct Debit (send the setup email). The first payment is {$amount}.{$monthly}",
                'date' => null];
        }

        return ['text' => ($portal ? 'Your' : 'The')." first setup fee payment ({$amount}) is collected by Direct Debit on {$day}.{$monthly}"
            .($portal ? ' Your tills keep working.' : ' The tills keep working.'), 'date' => self::date($first)?->format('Y-m-d')];
    }

    /** The day GoCardless takes it (its payment's charge date), else the invoice's due date. */
    private static function date(Invoice $first): ?CarbonImmutable
    {
        $payment = GoCardlessPayment::withoutCompanyScope()->where('invoice_id', $first->id)->latest('created_at')->latest('id')->first();

        return $payment !== null && $payment->charge_date !== null ? $payment->charge_date : $first->due_date;
    }

    private static function day(Invoice $first): string
    {
        return self::date($first)?->format('j M Y') ?? 'its due date';
    }
}
