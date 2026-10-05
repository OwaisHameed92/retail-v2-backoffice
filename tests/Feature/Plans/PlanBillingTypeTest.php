<?php

use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use Inertia\Testing\AssertableInertia;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

/* Owner rules (2026-10-05): a plan is setup only, setup + monthly/yearly, or monthly/yearly only. */

test('each plan type saves only the prices it uses', function () {
    $this->actingAs(planAdmin(), 'admin');

    $this->post(route('admin.plans.store'), planPayload(['code' => 'once', 'name' => 'Once', 'billing_type' => 'setupOnly', 'setup_fee' => '499']))->assertRedirect();
    $this->post(route('admin.plans.store'), planPayload(['code' => 'both', 'name' => 'Both', 'billing_type' => 'setupAndRecurring', 'setup_fee' => '199']))->assertRedirect();
    $this->post(route('admin.plans.store'), planPayload(['code' => 'monthly', 'name' => 'Monthly', 'billing_type' => 'recurringOnly', 'setup_fee' => '199']))->assertRedirect();

    $once = Plan::query()->where('code', 'once')->sole();
    $both = Plan::query()->where('code', 'both')->sole();
    $monthly = Plan::query()->where('code', 'monthly')->sole();

    expect($once->billing_type)->toBe(PlanBillingType::SetupOnly)
        ->and([$once->setup_fee, $once->price_monthly, $once->price_yearly])->toBe(['499.00', '0.00', '0.00'])
        ->and($both->billing_type)->toBe(PlanBillingType::SetupAndRecurring)
        ->and([$both->setup_fee, $both->price_monthly])->toBe(['199.00', '30.00'])
        ->and($monthly->billing_type)->toBe(PlanBillingType::RecurringOnly)
        ->and($monthly->setup_fee)->toBe('0.00');

    $this->get(route('admin.plans.edit', $once))->assertInertia(fn (AssertableInertia $page) => $page->where('plan.billingType', 'setupOnly'));
});

test('a setup fee type needs a fee and a recurring type needs a price', function () {
    $this->actingAs(planAdmin(), 'admin');

    $this->post(route('admin.plans.store'), planPayload(['billing_type' => 'setupOnly', 'setup_fee' => '0']))->assertSessionHasErrors('setup_fee');
    $this->post(route('admin.plans.store'), planPayload(['billing_type' => 'setupAndRecurring', 'setup_fee' => '0']))->assertSessionHasErrors('setup_fee');
    $this->post(route('admin.plans.store'), planPayload(['billing_type' => 'recurringOnly', 'price_monthly' => '0', 'price_yearly' => '0']))->assertSessionHasErrors('price_monthly');
    $this->post(route('admin.plans.store'), planPayload(['billing_type' => 'weekly']))->assertSessionHasErrors('billing_type');
});

test('plans saved before the type existed get it from their prices', function () {
    expect(PlanBillingType::infer('300.00', '0.00', '0.00'))->toBe(PlanBillingType::SetupOnly)
        ->and(PlanBillingType::infer('300.00', '25.00', '0.00'))->toBe(PlanBillingType::SetupAndRecurring)
        ->and(PlanBillingType::infer('0.00', '25.00', '250.00'))->toBe(PlanBillingType::RecurringOnly)
        ->and(Plan::factory()->create(['setup_fee' => '0.00'])->billingType())->toBe(PlanBillingType::RecurringOnly);
});
