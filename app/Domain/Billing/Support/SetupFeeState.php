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

/**
 * Where a company stands with its setup fee, the one-time "upfront" amount (owner rules 2026-10-05: always paid by
 * hand, cash, card or bank transfer, optionally in instalments; never by Direct Debit):
 *
 * - none: nothing to pay (no fee, £0 recorded, waived, or every setup fee invoice voided);
 * - unpaid: a fee is due and nothing is paid yet (not invoiced, or no instalment paid);
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

        if ($account->setup_fee_invoiced_at !== null || $account->upfront_recorded_at !== null) {
            return new self(self::NONE, '0.00', '0.00', 0, 0, null, $account->setup_fee_invoiced_at !== null);
        }

        $totals = SetupFee::totals($company, $account);

        if (Money::isZero($totals['gross'])) {
            return new self(self::NONE, '0.00', '0.00', 0, 0, null, false);
        }

        return new self(self::UNPAID, $totals['gross'], '0.00', count(SetupFee::schedule($company, $account)), 0, null, false);
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
