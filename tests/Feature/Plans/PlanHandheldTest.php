<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

/*
 * Owner 2026-10-21: `plans.includes_handheld` (portal only, never a till Feature). Backfilled from the prices, defaulted
 * from them for new plans, changeable by billing admins on the plan form.
 */

beforeEach(function () {
    $this->withoutVite();
});

it('backfills true for plans with a monthly or yearly price and false for setup only (archived plans too)', function () {
    $plans = [
        'standard' => ['30.00', '300.00', '0.00'],
        'setup-monthly' => ['25.00', '0.00', '299.00'],
        'monthly-only' => ['35.00', '350.00', '0.00'],
        'yearly-only' => ['0.00', '300.00', '0.00'],
        'setup-only' => ['0.00', '0.00', '499.00'],
    ];

    foreach ($plans as $code => [$monthly, $yearly, $fee]) {
        Plan::factory()->create(['code' => $code, 'price_monthly' => $monthly, 'price_yearly' => $yearly, 'setup_fee' => $fee]);
    }
    Plan::query()->where('code', 'monthly-only')->sole()->delete();
    DB::table('plans')->where('code', '!=', 'setup-only')->update(['includes_handheld' => false]);
    DB::table('plans')->where('code', 'setup-only')->update(['includes_handheld' => true]);

    $migration = require database_path('migrations/2026_12_03_100000_add_includes_handheld_to_plans.php');
    (fn () => $this->backfill())->call($migration);

    expect(DB::table('plans')->orderBy('code')->pluck('includes_handheld', 'code')->map(fn ($value) => (bool) $value)->all())->toBe([
        'monthly-only' => true,
        'setup-monthly' => true,
        'setup-only' => false,
        'standard' => true,
        'yearly-only' => true,
    ]);
});

it('defaults a new plan from its prices when the flag is not given', function () {
    expect(Plan::factory()->create()->includes_handheld)->toBeTrue()
        ->and(Plan::factory()->create(['price_monthly' => '0.00', 'price_yearly' => '0.00', 'setup_fee' => '499.00'])->includes_handheld)->toBeFalse()
        ->and(Plan::factory()->create(['price_monthly' => '0.00', 'price_yearly' => '0.00', 'includes_handheld' => true])->includes_handheld)->toBeTrue();
});

it('is not a till feature: the licence token list stays the till\'s 11 names', function () {
    expect(Feature::values())->toHaveCount(11)->not->toContain('handheld')->not->toContain('includes_handheld');
});

it('saves the flag from the plan form for owner and accounts admins', function (AdminRole $role) {
    $this->actingAs(planAdmin($role), 'admin');

    $this->post('/admin/plans', planPayload(['code' => 'monthly-default']))->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/admin/plans', planPayload(['code' => 'monthly-without', 'includes_handheld' => false]))->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/admin/plans', planPayload(['code' => 'setup-with', 'billing_type' => 'setupOnly', 'setup_fee' => '499', 'includes_handheld' => true]))
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->post('/admin/plans', planPayload(['code' => 'setup-default', 'billing_type' => 'setupOnly', 'setup_fee' => '499']))->assertRedirect();

    expect(Plan::query()->orderBy('code')->pluck('includes_handheld', 'code')->all())->toBe([
        'monthly-default' => true,
        'monthly-without' => false,
        'setup-default' => false,
        'setup-with' => true,
    ]);
    expect(AuditLog::query()->where('action', 'plan.created')->get()->every(fn (AuditLog $log) => array_key_exists('includes_handheld', $log->after ?? [])))->toBeTrue();

    $this->post('/admin/plans', planPayload(['code' => 'bad-flag', 'includes_handheld' => 'maybe']))->assertSessionHasErrors('includes_handheld');
})->with([AdminRole::Owner, AdminRole::Accounts]);

it('changes the flag on edit, audits it, and keeps it when an older client leaves it out', function () {
    $this->actingAs(planAdmin(), 'admin');
    $plan = Plan::factory()->create(['code' => 'standard', 'name' => 'Standard', 'description' => 'Everything a convenience store needs.', 'sort_order' => 10,
        'features' => [Feature::Loyalty, Feature::Promotions]]);

    $this->put("/admin/plans/{$plan->id}", planPayload(['includes_handheld' => false]))->assertRedirect()->assertSessionHasNoErrors();
    expect($plan->fresh()->includes_handheld)->toBeFalse();
    $log = AuditLog::query()->where('action', 'plan.updated')->sole();
    expect($log->before)->toBe(['includes_handheld' => true])->and($log->after)->toBe(['includes_handheld' => false]);

    $this->put("/admin/plans/{$plan->id}", planPayload())->assertRedirect();
    expect($plan->fresh()->includes_handheld)->toBeFalse()->and(AuditLog::query()->where('action', 'plan.updated')->count())->toBe(1);

    $this->get("/admin/plans/{$plan->id}")->assertInertia(fn (Assert $page) => $page->where('plan.includesHandheld', false)
        ->where('activity.0.changes.0', ['label' => 'Handheld (PDA) app included', 'from' => 'Yes', 'to' => 'No']));
    $this->get('/admin/plans')->assertInertia(fn (Assert $page) => $page->where('plans.data.0.includesHandheld', false));
});

it('forbids admins without billing.manage from changing the flag', function (AdminRole $role) {
    $plan = Plan::factory()->create(['code' => 'standard']);

    $this->actingAs(planAdmin($role), 'admin')->put("/admin/plans/{$plan->id}", planPayload(['includes_handheld' => false]))->assertForbidden();
    expect($plan->fresh()->includes_handheld)->toBeTrue();
})->with([AdminRole::Sales, AdminRole::Support]);
