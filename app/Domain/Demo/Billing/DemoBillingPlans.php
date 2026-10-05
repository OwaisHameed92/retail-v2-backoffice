<?php

namespace App\Domain\Demo\Billing;

use App\Domain\Plans\Actions\CreatePlan;
use App\Domain\Plans\Data\PlanInput;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Support\Money;

/**
 * The three plans of the billing rules (owner, 2026-10-05), made by `demo:billing` when missing (found by code, else
 * by exact name). Real plans, active but not public: staff can use them for real customers too.
 */
final class DemoBillingPlans
{
    public const SETUP_ONLY = 'setup-only';

    public const SETUP_MONTHLY = 'setup-monthly';

    public const MONTHLY_ONLY = 'monthly-only';

    /** code => [name, type, setup fee, monthly per till, description]. */
    public const PLANS = [
        self::SETUP_ONLY => ['Setup only', PlanBillingType::SetupOnly, '1200.00', '0.00', 'One setup fee of £1,200 paid by hand. Paid in full, the licence does not expire.'],
        self::SETUP_MONTHLY => ['Setup + monthly', PlanBillingType::SetupAndRecurring, '1200.00', '12.00', 'Setup fee of £1,200 paid by hand, then £12 per till a month by Direct Debit.'],
        self::MONTHLY_ONLY => ['Monthly only', PlanBillingType::RecurringOnly, '0.00', '120.00', 'No setup fee. £120 per till a month by Direct Debit.'],
    ];

    public function __construct(private readonly CreatePlan $createPlan) {}

    /** The plan, made when missing. */
    public function ensure(string $code): Plan
    {
        [$name, $type, $setup, $monthly, $description] = self::PLANS[$code];

        $existing = Plan::query()->where('is_active', true)->where(fn ($q) => $q->where('code', $code)->orWhere('name', $name))->orderByRaw('case when code = ? then 0 else 1 end', [$code])->first();

        if ($existing !== null) {
            return $existing;
        }

        $free = Plan::withTrashed()->where('code', $code)->exists() ? $code.'-'.strtolower(substr((string) str()->ulid(), -6)) : $code;

        return $this->createPlan->handle(new PlanInput(
            name: $name,
            code: $free,
            description: $description,
            priceMonthly: $monthly,
            priceYearly: Money::mul($monthly, '12'),
            features: Feature::cases(),
            setupFee: $setup,
            billingType: $type,
        ));
    }
}
