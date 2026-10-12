<?php

namespace App\Domain\Billing\Support;

use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

/**
 * Where a company stands with its setup fee, the one-time "upfront" amount (owner rules 2026-10-05: always paid by
 * hand, cash, card or bank transfer, optionally in instalments; never by Direct Debit):
 *
 * - none: nothing to pay (no fee, £0 recorded, waived, or every setup fee invoice voided with no corrected draft);
 * - unpaid: a fee is due and nothing is paid yet (not invoiced, or no instalment paid, or every setup fee invoice
 *   voided and a corrected draft waits to be issued: "Void and re-issue", audit BILL-2);
 * - partPaid: some instalments paid in full (a part payment of the first one still counts as unpaid);
 * - paid: every setup fee invoice paid.
 *
 * Amounts are VAT inclusive. `nextDue` is the due date of the next unpaid instalment.
 */
final readonly class SetupFeeState
{
    public const NONE = 'none';

    public const UNPAID = 'unpaid';

    public const PART_PAID = 'partPaid';

    public const PAID = 'paid';

    private function __construct(
        public string $status,
        public string $total,
        public string $paid,
        public int $parts,
        public int $paidParts,
        public ?CarbonImmutable $nextDue,
        public bool $invoiced,
    ) {}

    public static function for(Company $company, BillingAccount $account): self
    {
        $invoices = Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->where('kind', InvoiceKind::SetupFee->value)
            ->whereNotIn('status', [InvoiceStatus::Draft->value, InvoiceStatus::Void->value])
            ->orderBy('due_date')->orderBy('sequence')->get();

        if ($invoices->isNotEmpty()) {
            $total = Money::sum($invoices->pluck('total'));
            $balance = Money::sum($invoices->pluck('balance'));
            $paidParts = $invoices->filter(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Paid)->count();
            $next = $invoices->first(fn (Invoice $invoice) => $invoice->status !== InvoiceStatus::Paid);
            $status = match (true) {
                $paidParts === $invoices->count() => self::PAID,
                $paidParts > 0 => self::PART_PAID,
                default => self::UNPAID,
            };

            return new self($status, $total, Money::sub($total, $balance), $invoices->count(), $paidParts, $next?->due_date, true);
        }

        // BILL-2: every setup fee invoice is void but a corrected draft replaces one: the fee is still owed.
        $corrected = self::correctedDrafts($company);

        if ($corrected->isNotEmpty()) {
            return new self(self::UNPAID, Money::sum($corrected->pluck('total')), '0.00', $corrected->count(), 0, null, true);
        }

        if ($account->setup_fee_invoiced_at !== null || $account->upfront_recorded_at !== null) {
            return new self(self::NONE, '0.00', '0.00', 0, 0, null, $account->setup_fee_invoiced_at !== null);
        }

        $totals = SetupFee::totals($company, $account);

        if (Money::isZero($totals['gross'])) {
            return new self(self::NONE, '0.00', '0.00', 0, 0, null, false);
        }

        return new self(self::UNPAID, $totals['gross'], '0.00', count(SetupFee::schedule($company, $account)), 0, null, false);
    }

    /**
     * Draft setup fee invoices that replace a void one (VoidInvoice "create a corrected draft"). Only read when no
     * setup fee invoice is issued, so a business with an issued or paid one never changes state because of a draft.
     *
     * @return Collection<int, Invoice>
     */
    private static function correctedDrafts(Company $company): Collection
    {
        return Invoice::withoutCompanyScope()->where('company_id', $company->id)
            ->where('kind', InvoiceKind::SetupFee->value)->where('status', InvoiceStatus::Draft->value)
            ->whereIn('replaces_invoice_id', Invoice::withoutCompanyScope()->select('id')->where('company_id', $company->id)
                ->where('kind', InvoiceKind::SetupFee->value)->where('status', InvoiceStatus::Void->value))
            ->get();
    }

    /** Nothing more to pay: paid in full, or there was nothing to pay. */
    public function isSettled(): bool
    {
        return in_array($this->status, [self::NONE, self::PAID], true);
    }

    /** At least the first payment is in (or nothing is due): the recurring Direct Debit may start. */
    public function isStarted(): bool
    {
        return $this->status !== self::UNPAID;
    }

    public function owed(): string
    {
        return Money::sub($this->total, $this->paid);
    }

    public function label(): string
    {
        return match ($this->status) {
            self::NONE => 'Nothing to pay',
            self::UNPAID => 'Unpaid',
            self::PART_PAID => "Part paid ({$this->paidParts} of {$this->parts})",
            default => 'Paid',
        };
    }
}
