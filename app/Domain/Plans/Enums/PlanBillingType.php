<?php

namespace App\Domain\Plans\Enums;

use App\Domain\Shared\Support\Money;

/**
 * How a plan is paid for (owner rules, 2026-10-05). The setup fee (the one-time "upfront" amount) is always paid by
 * hand (cash, card or bank transfer) and recorded by an admin; the monthly or yearly fee is always collected by
 * GoCardless Direct Debit.
 *
 * - setupOnly: one-time setup fee, nothing recurring. Paid in full = a full licence that does not run out
 *   (`billing.setup_only.years`, renewed automatically). Before it is paid the tills run on the trial.
 * - setupAndRecurring: setup fee by hand + recurring Direct Debit.
 * - recurringOnly: recurring Direct Debit, no setup fee.
 */
enum PlanBillingType: string
{
    case SetupOnly = 'setupOnly';
    case SetupAndRecurring = 'setupAndRecurring';
    case RecurringOnly = 'recurringOnly';

    public function label(): string
    {
        return match ($this) {
            self::SetupOnly => 'Setup fee only',
            self::SetupAndRecurring => 'Setup fee + monthly or yearly',
            self::RecurringOnly => 'Monthly or yearly only',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::SetupOnly => 'One payment by hand. Once paid in full the licence does not expire.',
            self::SetupAndRecurring => 'Setup fee paid by hand, then a Direct Debit each month or year.',
            self::RecurringOnly => 'No setup fee. A Direct Debit each month or year.',
        };
    }

    public function hasSetupFee(): bool
    {
        return $this !== self::RecurringOnly;
    }

    public function recurs(): bool
    {
        return $this !== self::SetupOnly;
    }

    /** The type the prices imply, for plans saved before the type existed. */
    public static function infer(string $setupFee, string $monthly, string $yearly): self
    {
        if (Money::isZero($setupFee)) {
            return self::RecurringOnly;
        }

        return Money::isZero($monthly) && Money::isZero($yearly) ? self::SetupOnly : self::SetupAndRecurring;
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label(), 'description' => $type->description()], self::cases());
    }
}
