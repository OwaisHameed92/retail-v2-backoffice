<?php

namespace App\Domain\Demo\Billing;

use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Demo\Support\DemoBusinesses;

/**
 * The billing cases `demo:billing` can show, one demo business each (docs/billing-flow.md → "See it"). Only
 * SetupMonthly is made by default (owner, 2026-10-05); the others with --scenario.
 */
enum DemoBillingScenario: string
{
    case SetupMonthly = 'setup-monthly';
    case SetupOnlyPaid = 'setup-only-paid';
    case SetupOnlyUnpaid = 'setup-only-unpaid';
    case WaitingForDirectDebit = 'waiting-for-dd';
    case PaymentFailed = 'payment-failed';
    case Instalments = 'instalments';

    public const DEFAULT = self::SetupMonthly;

    public function businessName(): string
    {
        return DemoBusinesses::NAME_PREFIX.match ($this) {
            self::SetupMonthly => 'Setup + monthly',
            self::SetupOnlyPaid => 'Setup only, paid',
            self::SetupOnlyUnpaid => 'Setup only, on trial',
            self::WaitingForDirectDebit => 'Waiting for Direct Debit',
            self::PaymentFailed => 'Direct Debit failed',
            self::Instalments => 'Setup fee in instalments',
        };
    }

    /** The plan code (DemoBillingPlans). */
    public function plan(): string
    {
        return match ($this) {
            self::SetupOnlyPaid, self::SetupOnlyUnpaid => DemoBillingPlans::SETUP_ONLY,
            self::PaymentFailed => DemoBillingPlans::MONTHLY_ONLY,
            default => DemoBillingPlans::SETUP_MONTHLY,
        };
    }

    /** What the business shows. */
    public function story(): string
    {
        return match ($this) {
            self::SetupMonthly => 'Setup fee paid by bank transfer, Direct Debit active, last month collected, next collection shown.',
            self::SetupOnlyPaid => 'Setup fee paid in full by card: full licence for 10 years, nothing more to pay.',
            self::SetupOnlyUnpaid => 'Setup fee not paid: on the free trial, with the days left and the day the tills lock.',
            self::WaitingForDirectDebit => 'Setup fee paid in cash, no Direct Debit yet: inside the 3-day deadline, reminder sent, lock date shown.',
            self::PaymentFailed => 'Direct Debit payment failed 4 days ago: overdue, with the day the tills lock if unpaid.',
            self::Instalments => 'Setup fee in 2 instalments: first paid by bank transfer, second due next month. Direct Debit active.',
        };
    }

    /** The BillingStatus state the business is in right after it is made. */
    public function expectedState(): string
    {
        return match ($this) {
            self::SetupMonthly, self::SetupOnlyPaid => BillingStatus::PAID,
            self::SetupOnlyUnpaid => BillingStatus::TRIAL,
            self::WaitingForDirectDebit => BillingStatus::WAITING_FOR_DD,
            self::PaymentFailed => BillingStatus::PAYMENT_FAILED,
            self::Instalments => BillingStatus::INSTALMENT_DUE,
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $scenario) => $scenario->value, self::cases());
    }
}
