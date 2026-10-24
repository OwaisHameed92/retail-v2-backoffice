<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Plans\Actions\CreatePlan;
use App\Domain\Plans\Actions\UpdatePlan;
use App\Domain\Plans\Data\PlanInput;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Enums\PlanBillingType;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

/*
 * OS-7 (owner 2026-10-24, option B): a plan can include the online store add-on (portal only, never a till Feature),
 * like the handheld app. Recurring plans only; its own monthly price (blank = the default £20). Hidden on PK.
 */

beforeEach(function () {
    $this->withoutVite();
});

function osPlanPayload(array $overrides = []): array
{
    return planPayload(['includes_online_store' => true, 'online_store_monthly_price' => '', ...$overrides]);
}

it('adds the columns without changing any existing plan: every plan stays without the online store', function () {
    $plan = Plan::factory()->create(['code' => 'standard']);

    expect(DB::table('plans')->where('id', $plan->id)->value('includes_online_store'))->toBeIn([0, false, '0'])
        ->and(DB::table('plans')->where('id', $plan->id)->value('online_store_monthly_price'))->toBeNull()
        ->and($plan->fresh()->includes_online_store)->toBeFalse();
});

it('is not a till feature: the licence token list stays the till\'s 11 names', function () {
    expect(Feature::values())->toHaveCount(11)->not->toContain('online_store')->not->toContain('includes_online_store');
});

it('saves the online store and its price from the plan form for billing admins, audited', function (AdminRole $role) {
    $this->actingAs(planAdmin($role), 'admin');

    $this->post('/admin/plans', osPlanPayload(['code' => 'monthly-store']))->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/admin/plans', osPlanPayload(['code' => 'monthly-cheap', 'online_store_monthly_price' => '£12.50']))->assertSessionHasNoErrors();
    $this->post('/admin/plans', osPlanPayload(['code' => 'monthly-free', 'online_store_monthly_price' => '0']))->assertSessionHasNoErrors();
    $this->post('/admin/plans', planPayload(['code' => 'monthly-plain']))->assertSessionHasNoErrors();
    $this->post('/admin/plans', planPayload(['code' => 'monthly-unticked', 'includes_online_store' => false, 'online_store_monthly_price' => '15']))->assertSessionHasNoErrors();

    $plans = Plan::query()->get()->keyBy('code');
    expect($plans['monthly-store']->includes_online_store)->toBeTrue()->and($plans['monthly-store']->online_store_monthly_price)->toBeNull()
        ->and($plans['monthly-cheap']->online_store_monthly_price)->toBe('12.50')
        ->and($plans['monthly-free']->online_store_monthly_price)->toBe('0.00')
        ->and($plans['monthly-plain']->includes_online_store)->toBeFalse()
        ->and($plans['monthly-unticked']->includes_online_store)->toBeFalse()->and($plans['monthly-unticked']->online_store_monthly_price)->toBeNull();

    $audit = AuditLog::query()->where('action', 'plan.created')->where('subject_id', $plans['monthly-cheap']->id)->sole();
    expect($audit->after['includes_online_store'])->toBeTrue()->and($audit->after['online_store_monthly_price'])->toBe('12.50');
})->with([AdminRole::Owner, AdminRole::Accounts]);

it('only lets recurring plans include it, and refuses a bad or negative price', function () {
    $this->actingAs(planAdmin(), 'admin');

    $this->post('/admin/plans', osPlanPayload(['code' => 'setup-store', 'billing_type' => 'setupOnly', 'setup_fee' => '499']))
        ->assertSessionHasErrors(['includes_online_store' => 'Only a monthly or yearly plan can include the online store. Untick it, or choose a plan type with a monthly fee.']);
    $this->post('/admin/plans', osPlanPayload(['code' => 'bad-price', 'online_store_monthly_price' => 'abc']))->assertSessionHasErrors('online_store_monthly_price');
    $this->post('/admin/plans', osPlanPayload(['code' => 'neg-price', 'online_store_monthly_price' => '-5']))->assertSessionHasErrors('online_store_monthly_price');
    $this->post('/admin/plans', osPlanPayload(['code' => 'setup-monthly', 'billing_type' => 'setupAndRecurring', 'setup_fee' => '499']))->assertSessionHasNoErrors();
    $this->post('/admin/plans', osPlanPayload(['code' => 'monthly-only', 'billing_type' => 'recurringOnly', 'setup_fee' => '0']))->assertSessionHasNoErrors();

    expect(Plan::query()->orderBy('code')->pluck('includes_online_store', 'code')->all())->toBe(['monthly-only' => true, 'setup-monthly' => true]);

    // The actions refuse it too, also when an older client leaves the box out and the plan becomes setup only.
    $input = fn (PlanBillingType $type, ?bool $store) => new PlanInput(name: 'X', code: 'x-plan', description: null, priceMonthly: '30', priceYearly: '0',
        setupFee: '499', billingType: $type, includesOnlineStore: $store);
    expect(fn () => app(CreatePlan::class)->handle($input(PlanBillingType::SetupOnly, true)))->toThrow(ValidationException::class, 'Only a monthly or yearly plan');

    $plan = Plan::query()->where('code', 'monthly-only')->sole();
    expect(fn () => app(UpdatePlan::class)->handle($plan, new PlanInput(name: $plan->name, code: $plan->code, description: null, priceMonthly: '0',
        priceYearly: '0', setupFee: '499', billingType: PlanBillingType::SetupOnly)))->toThrow(ValidationException::class, 'Only a monthly or yearly plan');
    expect($plan->fresh()->billingType())->toBe(PlanBillingType::RecurringOnly);
});

it('changes on edit (audited, in the activity), keeps it when an older client leaves it out, and clears the price when unticked', function () {
    $this->actingAs(planAdmin(), 'admin');
    $plan = Plan::factory()->create(['code' => 'standard', 'name' => 'Standard', 'description' => 'Everything a convenience store needs.', 'sort_order' => 10,
        'features' => [Feature::Loyalty, Feature::Promotions]]);

    $this->put("/admin/plans/{$plan->id}", osPlanPayload(['online_store_monthly_price' => '18']))->assertRedirect()->assertSessionHasNoErrors();
    expect($plan->fresh()->includes_online_store)->toBeTrue()->and($plan->fresh()->online_store_monthly_price)->toBe('18.00');
    $log = AuditLog::query()->where('action', 'plan.updated')->sole();
    expect($log->before)->toEqual(['includes_online_store' => false, 'online_store_monthly_price' => null])
        ->and($log->after)->toEqual(['includes_online_store' => true, 'online_store_monthly_price' => '18.00']);

    $this->put("/admin/plans/{$plan->id}", planPayload())->assertRedirect();
    expect($plan->fresh()->includes_online_store)->toBeTrue()->and(AuditLog::query()->where('action', 'plan.updated')->count())->toBe(1);

    $this->get("/admin/plans/{$plan->id}")->assertInertia(fn (Assert $page) => $page
        ->where('plan.includesOnlineStore', true)
        ->where('plan.onlineStoreMonthlyPrice', '18.00')
        ->where('plan.onlineStore', ['price' => '18.00', 'priceLabel' => '£18.00'])
        ->where('onlineStore', ['defaultPrice' => '20.00', 'serverOn' => false])
        ->where('activity.0.changes', [
            ['label' => 'Online store included', 'from' => 'No', 'to' => 'Yes'],
            ['label' => 'Online store fee per month', 'from' => 'None', 'to' => '£18.00'],
        ]));
    $this->get('/admin/plans')->assertInertia(fn (Assert $page) => $page->where('plans.data.0.includesOnlineStore', true));

    $this->put("/admin/plans/{$plan->id}", planPayload(['includes_online_store' => false, 'online_store_monthly_price' => '18']))->assertSessionHasNoErrors();
    expect($plan->fresh()->includes_online_store)->toBeFalse()->and($plan->fresh()->online_store_monthly_price)->toBeNull();
    $this->get("/admin/plans/{$plan->id}")->assertInertia(fn (Assert $page) => $page->where('plan.onlineStore', null));
});

it('gives the plan form its online store settings: the default price and the server switch', function () {
    config(['online_store.enabled' => true]);
    $plan = Plan::factory()->create(['code' => 'standard']);
    $this->actingAs(planAdmin(), 'admin');

    $this->get('/admin/plans/create')->assertInertia(fn (Assert $page) => $page->where('onlineStore', ['defaultPrice' => '20.00', 'serverOn' => true]));
    $this->get("/admin/plans/{$plan->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('onlineStore', ['defaultPrice' => '20.00', 'serverOn' => true])
        ->where('plan.includesOnlineStore', false)
        ->where('plan.onlineStoreMonthlyPrice', null));
});

it('forbids admins without billing.manage from changing it', function (AdminRole $role) {
    $plan = Plan::factory()->create(['code' => 'standard']);

    $this->actingAs(planAdmin($role), 'admin')->put("/admin/plans/{$plan->id}", osPlanPayload())->assertForbidden();
    expect($plan->fresh()->includes_online_store)->toBeFalse();
})->with([AdminRole::Sales, AdminRole::Support]);

it('Pakistan: hidden on the plan pages and refused on save; the plan never includes it', function () {
    $this->actingAs(planAdmin(), 'admin');
    $plan = Plan::factory()->create(['code' => 'standard', 'includes_online_store' => true]);
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);

    $this->get('/admin/plans/create')->assertInertia(fn (Assert $page) => $page->where('onlineStore', null));
    $this->get("/admin/plans/{$plan->id}")->assertInertia(fn (Assert $page) => $page->where('onlineStore', null)->where('plan.onlineStore', null));
    $this->get('/admin/plans')->assertInertia(fn (Assert $page) => $page->where('plans.data.0.includesOnlineStore', false));

    $this->post('/admin/plans', osPlanPayload(['code' => 'pk-store']))->assertSessionHasErrors(['includes_online_store' => 'Online stores are not offered in this country.']);
    $this->post('/admin/plans', planPayload(['code' => 'pk-plain', 'includes_online_store' => false]))->assertSessionHasNoErrors();
    expect(Plan::query()->where('code', 'pk-store')->exists())->toBeFalse();
});
