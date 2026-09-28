<?php

use App\Domain\Plans\Models\Plan;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

beforeEach(function () {
    $this->actingAs(planAdmin(), 'admin');
});

it('rejects invalid input', function (array $overrides, string $field) {
    Plan::factory()->archived()->create(['code' => 'taken']);

    $this->from('/admin/plans/create')
        ->post('/admin/plans', planPayload($overrides))
        ->assertRedirect('/admin/plans/create')
        ->assertSessionHasErrors($field);

    expect(Plan::query()->count())->toBe(0);
})->with([
    'missing name' => [['name' => ''], 'name'],
    'long name' => [['name' => str_repeat('a', 101)], 'name'],
    'missing code' => [['code' => ''], 'code'],
    'code with spaces' => [['code' => 'my plan'], 'code'],
    'code with double hyphen' => [['code' => 'my--plan'], 'code'],
    'code with trailing hyphen' => [['code' => 'plan-'], 'code'],
    'code too long' => [['code' => str_repeat('a', 51)], 'code'],
    'code used by an archived plan' => [['code' => 'taken'], 'code'],
    'long description' => [['description' => str_repeat('a', 501)], 'description'],
    'missing monthly price' => [['price_monthly' => ''], 'price_monthly'],
    'negative monthly price' => [['price_monthly' => '-1.00'], 'price_monthly'],
    'three decimal places' => [['price_monthly' => '30.005'], 'price_monthly'],
    'exponent' => [['price_monthly' => '1e3'], 'price_monthly'],
    'words' => [['price_yearly' => 'thirty'], 'price_yearly'],
    'price too large' => [['price_yearly' => '100000.00'], 'price_yearly'],
    'trial too long' => [['trial_days' => 91], 'trial_days'],
    'negative trial' => [['trial_days' => -1], 'trial_days'],
    'fractional trial' => [['trial_days' => 1.5], 'trial_days'],
    'trial grace too long' => [['trial_grace_days' => 31], 'trial_grace_days'],
    'grace too long' => [['grace_days' => 61], 'grace_days'],
    'unknown feature' => [['features' => ['loyalty', 'teleport']], 'features.1'],
    'duplicate feature' => [['features' => ['second_screen', 'second_screen']], 'features.0'],
    'features not a list' => [['features' => 'second_screen'], 'features'],
    'missing active flag' => [['is_active' => null], 'is_active'],
    'bad public flag' => [['is_public' => 'maybe'], 'is_public'],
    'negative sort order' => [['sort_order' => -5], 'sort_order'],
]);

it('explains the money format in plain words', function () {
    $this->post('/admin/plans', planPayload(['price_monthly' => '30.005']))
        ->assertSessionHasErrors(['price_monthly' => 'Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99.']);
});

it('accepts an empty feature list and a missing description', function () {
    $this->post('/admin/plans', planPayload(['features' => [], 'description' => null]))->assertSessionHasNoErrors();

    $plan = Plan::query()->sole();
    expect($plan->featureValues())->toBe([])
        ->and($plan->description)->toBeNull();
});

it('lets a plan keep its own code on update', function () {
    $plan = Plan::factory()->create(['code' => 'standard']);

    $this->put("/admin/plans/{$plan->id}", planPayload(['code' => 'standard']))->assertSessionHasNoErrors();
});

it('lower-cases and trims the code before validating', function () {
    $this->post('/admin/plans', planPayload(['code' => '  Pro-2026 ']))->assertSessionHasNoErrors();

    expect(Plan::query()->sole()->code)->toBe('pro-2026');
});
