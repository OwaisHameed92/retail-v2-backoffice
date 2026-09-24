<?php

use App\Domain\Plans\Actions\UpdatePlan;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use Inertia\Testing\AssertableInertia as Assert;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planInput;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    $this->admin = planAdmin();
    $this->actingAs($this->admin, 'admin');
});

it('lists current plans in sort order with counts', function () {
    Plan::factory()->create(['name' => 'Pro', 'code' => 'pro', 'sort_order' => 20, 'features' => Feature::cases()]);
    Plan::factory()->create(['name' => 'Standard', 'code' => 'standard', 'sort_order' => 10, 'price_per_till_monthly' => '30.00']);
    Plan::factory()->hidden()->create(['sort_order' => 30]);
    Plan::factory()->inactive()->create(['sort_order' => 40]);
    Plan::factory()->archived()->create(['sort_order' => 5]);

    $this->get('/admin/plans')
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/plans/index')
            ->has('plans.data', 4)
            ->where('plans.meta.total', 4)
            ->where('plans.data.0.name', 'Standard')
            ->where('plans.data.0.pricePerTillMonthly', '30.00')
            ->where('plans.data.0.status', 'active')
            ->where('plans.data.1.featureCount', 10)
            ->where('plans.data.2.status', 'hidden')
            ->where('plans.data.3.status', 'inactive')
            ->where('filters.status', null)
            ->where('counts', ['current' => 4, 'all' => 5, 'active' => 2, 'hidden' => 1, 'inactive' => 1, 'archived' => 1]));
});

it('filters by status', function (string $status, int $expected) {
    Plan::factory()->count(2)->create();
    Plan::factory()->hidden()->create();
    Plan::factory()->inactive()->create();
    Plan::factory()->archived()->count(3)->create();

    $this->get("/admin/plans?status={$status}")
        ->assertInertia(fn (Assert $page) => $page->where('plans.meta.total', $expected));
})->with([
    'active' => ['active', 2],
    'hidden' => ['hidden', 1],
    'inactive' => ['inactive', 1],
    'archived' => ['archived', 3],
    'all' => ['all', 7],
    'unknown falls back to current' => ['nonsense', 4],
]);

it('searches name, code and description', function () {
    Plan::factory()->create(['name' => 'Standard', 'code' => 'standard', 'description' => 'Core till']);
    Plan::factory()->create(['name' => 'Pro', 'code' => 'pro-2026', 'description' => 'With AI']);

    $this->get('/admin/plans?search=2026')->assertInertia(fn (Assert $page) => $page->has('plans.data', 1)->where('plans.data.0.name', 'Pro'));
    $this->get('/admin/plans?search=core')->assertInertia(fn (Assert $page) => $page->has('plans.data', 1)->where('plans.data.0.name', 'Standard'));
});

it('sorts by whitelisted columns only', function () {
    Plan::factory()->create(['name' => 'Cheap', 'price_per_till_monthly' => '9.99', 'sort_order' => 1]);
    Plan::factory()->create(['name' => 'Dear', 'price_per_till_monthly' => '100.00', 'sort_order' => 2]);
    Plan::factory()->create(['name' => 'Mid', 'price_per_till_monthly' => '30.00', 'sort_order' => 3]);

    $this->get('/admin/plans?sort=price_per_till_monthly&direction=desc')
        ->assertInertia(fn (Assert $page) => $page->where('plans.data.0.name', 'Dear')->where('plans.data.2.name', 'Cheap'));

    $this->get('/admin/plans?sort=description')
        ->assertInertia(fn (Assert $page) => $page->where('plans.meta.sort', null)->where('plans.data.0.name', 'Cheap'));
});

it('paginates', function () {
    Plan::factory()->count(12)->create();

    $this->get('/admin/plans?perPage=10&page=2')
        ->assertInertia(fn (Assert $page) => $page->has('plans.data', 2)->where('plans.meta.lastPage', 2));
});

it('shows the create form with features and defaults', function () {
    Plan::factory()->create(['sort_order' => 20]);

    $this->get('/admin/plans/create')
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/plans/create')
            ->has('features', 10)
            ->where('features.0', ['value' => 'stockControl', 'label' => 'Stock control', 'description' => Feature::StockControl->description()])
            ->where('defaults', ['trialDays' => 7, 'trialGraceDays' => 3, 'graceDays' => 7, 'sortOrder' => 30]));
});

it('creates a plan and opens it with a toast', function () {
    $response = $this->post('/admin/plans', planPayload(['features' => ['aiInsights', 'stockControl']]));

    $plan = Plan::query()->where('code', 'standard')->sole();
    $response->assertRedirect("/admin/plans/{$plan->id}")
        ->assertSessionHas('toast', fn (array $toast) => $toast['type'] === 'success' && $toast['message'] === 'Standard plan created.');

    expect($plan->featureValues())->toBe(['stockControl', 'aiInsights'])
        ->and($plan->is_public)->toBeTrue()
        ->and(AuditLog::query()->where('action', 'plan.created')->count())->toBe(1);
});

it('shows a plan with its activity', function () {
    $plan = Plan::factory()->create(['name' => 'Standard', 'price_per_till_monthly' => '30.00', 'price_per_till_yearly' => '300.00']);
    app(UpdatePlan::class)->handle($plan, planInput(code: $plan->code, monthly: '32.00', features: [Feature::StockControl, Feature::Staff]));

    $this->get("/admin/plans/{$plan->id}")
        ->assertInertia(fn (Assert $page) => $page
            ->component('admin/plans/show')
            ->where('plan.id', $plan->id)
            ->where('plan.pricePerTillMonthly', '32.00')
            ->where('plan.yearlySaving', '84.00')
            ->where('plan.yearlySavingPercent', 22)
            ->where('plan.isInUse', false)
            ->has('features', 10)
            ->has('activity', 1)
            ->where('activity.0.action', 'plan.updated')
            ->where('activity.0.actorName', $this->admin->name)
            ->where('activity.0.changes', fn ($changes) => collect($changes)->contains(fn ($c) => $c['label'] === 'Monthly price per till' && $c['from'] === '£30.00' && $c['to'] === '£32.00')));
});

it('shows archived plans read-only and 404s their edit form', function () {
    $plan = Plan::factory()->archived()->create();

    $this->get("/admin/plans/{$plan->id}")->assertInertia(fn (Assert $page) => $page->where('plan.status', 'archived'));
    $this->get("/admin/plans/{$plan->id}/edit")->assertNotFound();
    $this->put("/admin/plans/{$plan->id}", planPayload())->assertNotFound();
});

it('shows the edit form and saves changes', function () {
    $plan = Plan::factory()->create(['code' => 'standard']);

    $this->get("/admin/plans/{$plan->id}/edit")
        ->assertInertia(fn (Assert $page) => $page->component('admin/plans/edit')->where('plan.code', 'standard')->has('features', 10));

    $this->put("/admin/plans/{$plan->id}", planPayload(['name' => 'Standard 2026', 'grace_days' => 14]))
        ->assertRedirect("/admin/plans/{$plan->id}")
        ->assertSessionHas('toast.message', 'Standard 2026 plan saved.');

    expect($plan->fresh()->grace_days)->toBe(14);
});

it('says so when an edit changes nothing', function () {
    $plan = Plan::factory()->create(planPayload());

    $this->put("/admin/plans/{$plan->id}", planPayload())->assertSessionHas('toast.message', 'No changes to save.');

    expect(AuditLog::query()->count())->toBe(0);
});

it('archives, restores and duplicates over HTTP', function () {
    $plan = Plan::factory()->create(['name' => 'Standard', 'code' => 'standard']);

    $this->delete("/admin/plans/{$plan->id}")
        ->assertRedirect('/admin/plans')
        ->assertSessionHas('toast.message', 'Standard plan archived.');
    expect($plan->fresh()->trashed())->toBeTrue();

    $this->post("/admin/plans/{$plan->id}/restore")
        ->assertRedirect("/admin/plans/{$plan->id}")
        ->assertSessionHas('toast.message', 'Standard plan restored.');
    expect($plan->fresh()->trashed())->toBeFalse();

    $response = $this->post("/admin/plans/{$plan->id}/duplicate");
    $copy = Plan::query()->where('code', 'standard-copy')->sole();
    $response->assertRedirect("/admin/plans/{$copy->id}/edit")->assertSessionHas('toast');

    expect(AuditLog::query()->pluck('action')->all())->toEqualCanonicalizing(['plan.archived', 'plan.restored', 'plan.duplicated']);
});

it('passes a flashed toast to the next page once', function () {
    $plan = Plan::factory()->create();

    $this->withSession(['toast' => ['id' => 'abc', 'type' => 'success', 'message' => 'Saved.']])
        ->get("/admin/plans/{$plan->id}")
        ->assertInertia(fn (Assert $page) => $page->where('toast.message', 'Saved.'));
});

it('returns 404 for an unknown plan id', function () {
    $this->get('/admin/plans/01K5VB0000000000000000000Z')->assertNotFound();
});
