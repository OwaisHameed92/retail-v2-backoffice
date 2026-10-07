<?php

namespace App\Domain\Billing\Data;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\Support\MandateDeadline;
use App\Domain\Billing\Support\Vat;
use App\Domain\Plans\Models\Plan;

/**
 * Props for the "Billing" step of the tenant wizard and the trial approval dialog (module 1.13): whether this
 * admin may record the setup fee (upfront) payment, each active plan's setup fee (net, per till on a per-till plan), the VAT rate and the Direct Debit
 * deadline the owner gets.
 */
final class OnboardingBilling
{
    /**
     * @return array{canRecord: bool, setupFees: array<string, string>, setupFeePerTill: array<string, bool>, vatRate: string|null, deadlineDays: int, methods: list<array{value: string, label: string}>}
     */
    public static function options(mixed $admin): array
    {
        $plans = Plan::query()->where('is_active', true)->get();

        return [
            'canRecord' => $admin instanceof Admin && $admin->hasAbility(AdminRole::BILLING_MANAGE),
            'setupFees' => $plans->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->setup_fee])->all(),
            // P11: plans whose setup fee is charged for each till (the form shows fee × tills).
            'setupFeePerTill' => $plans->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->setupFeePerTill()])->all(),
            'vatRate' => Vat::enabled() ? Vat::rate() : null,
            'deadlineDays' => MandateDeadline::days(),
            'methods' => PaymentMethod::setupFeeOptions(),
        ];
    }
}
