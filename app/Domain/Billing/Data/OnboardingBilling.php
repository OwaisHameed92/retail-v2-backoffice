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
 * admin may record the upfront payment, each active plan's setup fee (net), the VAT rate and the Direct Debit
 * deadline the owner gets.
 */
final class OnboardingBilling
{
    /**
     * @return array{canRecord: bool, setupFees: array<string, string>, vatRate: string|null, deadlineDays: int, methods: list<array{value: string, label: string}>}
     */
    public static function options(mixed $admin): array
    {
        return [
            'canRecord' => $admin instanceof Admin && $admin->hasAbility(AdminRole::BILLING_MANAGE),
            'setupFees' => Plan::query()->where('is_active', true)->get(['id', 'setup_fee'])
                ->mapWithKeys(fn (Plan $plan) => [$plan->id => $plan->setup_fee])->all(),
            'vatRate' => Vat::enabled() ? Vat::rate() : null,
            'deadlineDays' => MandateDeadline::days(),
            'methods' => array_map(fn (PaymentMethod $method) => ['value' => $method->value, 'label' => $method->label()], [PaymentMethod::Cash, PaymentMethod::BankTransfer]),
        ];
    }
}
