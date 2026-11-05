<?php

namespace App\Domain\Billing\GoCardless\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Contracts\GoCardlessClient;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\ManualCollection;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Owner 2026-11-05: the per-business exception "Setup fee collected by Direct Debit" (`setup_fee_by_direct_debit`,
 * default off: the setup fee is paid by hand, owner rule 2026-10-05). Only on a Direct Debit instance (GoCardless
 * configured, never manual collection) and for a business that pays by Direct Debit. The legacy `setup_fee_method`
 * value `directDebit` is never read as this.
 *
 * Each unpaid setup fee instalment invoice gets one GoCardless payment for its balance, created
 * `billing.direct_debit.setup_fee_lead_days` before its due date and charged on the due date (or GoCardless' first
 * possible day, whichever is later): CollectSetupFeeByDirectDebit.
 */
final class SetupFeeByDirectDebit
{
    /** Staff may offer it here: a UK instance with GoCardless configured. */
    public static function available(): bool
    {
        return ! ManualCollection::active() && app(GoCardlessClient::class)->enabled();
    }

    /** This business's setup fee is collected by Direct Debit. */
    public static function on(BillingAccount $account): bool
    {
        return $account->setup_fee_by_direct_debit && $account->isDirectDebit() && ! ManualCollection::active();
    }

    /** How many days before an instalment's due date its payment is created (Bacs needs a few working days). */
    public static function leadDays(): int
    {
        return max(0, (int) config('billing.direct_debit.setup_fee_lead_days', 10));
    }

    /**
     * The day to charge an instalment: its due date, or the mandate's first possible day when that is later. Null
     * asks GoCardless for the first possible day (the mandate's day is not known and the due date is close).
     */
    public static function chargeDate(Invoice $invoice, ?string $earliest, CarbonImmutable $today): ?string
    {
        $due = ($invoice->due_date ?? $today)->max($today);

        if ($earliest !== null) {
            return $due->max(BillingDates::date($earliest))->format('Y-m-d');
        }

        return $due->greaterThan($today->addWeekdays(5)) ? $due->format('Y-m-d') : null;
    }

    /**
     * The business's setup fee invoices (issued, overdue, paid; never draft or void), in due order.
     *
     * @return Collection<int, Invoice>
     */
    public static function invoices(Company $company): Collection
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->where('kind', InvoiceKind::SetupFee->value)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->orderBy('due_date')->orderBy('sequence')->get();
    }

    /** The GoCardless payment that stands for an invoice (the latest one that was not cancelled or denied). */
    public static function paymentFor(Invoice $invoice): ?GoCardlessPayment
    {
        return GoCardlessPayment::withoutCompanyScope()->where('invoice_id', $invoice->id)
            ->whereNotIn('status', [PaymentStatus::Cancelled->value, PaymentStatus::CustomerApprovalDenied->value])
            ->latest('created_at')->first();
    }

    /**
     * Each setup fee instalment and where it stands, for the admin Billing tab and the tenant page: paid, scheduled
     * for a day, submitted, failed, waiting for the Direct Debit, or to pay by hand.
     *
     * @return list<array{number: string|null, label: string, amount: string, dueDate: string|null, state: string, stateLabel: string, chargeDate: string|null}>
     */
    public static function parts(Company $company, BillingAccount $account): array
    {
        $invoices = self::invoices($company);
        $on = self::on($account);

        return $invoices->values()->map(function (Invoice $invoice, int $i) use ($invoices, $on, $account) {
            $payment = self::paymentFor($invoice);
            [$state, $label, $date] = self::state($invoice, $payment, $on, $account->hasUsableMandate());

            return [
                'number' => $invoice->number,
                'label' => $invoices->count() > 1 ? 'Part '.($i + 1).' of '.$invoices->count() : 'Setup fee',
                'amount' => BillingFormat::money($invoice->total),
                'dueDate' => $invoice->due_date?->format('Y-m-d'),
                'state' => $state,
                'stateLabel' => $label,
                'chargeDate' => $date,
            ];
        })->all();
    }

    /**
     * @return array{0: string, 1: string, 2: string|null}
     */
    private static function state(Invoice $invoice, ?GoCardlessPayment $payment, bool $on, bool $mandate): array
    {
        $day = $payment?->charge_date?->format('Y-m-d');

        if ($invoice->status === InvoiceStatus::Paid) {
            return ['paid', $payment !== null && $payment->status->isCollected() ? 'Paid by Direct Debit' : 'Paid', $day];
        }

        if ($payment !== null) {
            return match (true) {
                $payment->status === PaymentStatus::Submitted => ['submitted', 'Submitted to the bank', $day],
                $payment->status->isPending() => ['scheduled', 'Scheduled', $day],
                $payment->status->isProblem() => ['failed', $payment->status->label(), $day],
                default => ['collected', $payment->status->label(), $day],
            };
        }

        return match (true) {
            ! $on => ['byHand', 'To pay by hand', null],
            ! $mandate => ['waiting', 'Waiting for the Direct Debit', null],
            default => ['planned', 'Upcoming', $invoice->due_date?->format('Y-m-d')],
        };
    }
}
