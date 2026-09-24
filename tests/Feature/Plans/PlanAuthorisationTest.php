<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Plans\Models\Plan;
use App\Models\User;

use function Tests\Feature\Plans\planAdmin;
use function Tests\Feature\Plans\planPayload;

require_once __DIR__.'/PlanTestHelpers.php';

beforeEach(function () {
    $this->withoutVite();
    $this->plan = Plan::factory()->create(['code' => 'existing']);
});

dataset('plan routes', [
    'index' => ['get', '/admin/plans'],
    'create' => ['get', '/admin/plans/create'],
    'store' => ['post', '/admin/plans'],
    'show' => ['get', '/admin/plans/{plan}'],
    'edit' => ['get', '/admin/plans/{plan}/edit'],
    'update' => ['put', '/admin/plans/{plan}'],
    'archive' => ['delete', '/admin/plans/{plan}'],
    'restore' => ['post', '/admin/plans/{plan}/restore'],
    'duplicate' => ['post', '/admin/plans/{plan}/duplicate'],
]);

function planRequest(string $method, string $uri, Plan $plan): array
{
    return [$method, str_replace('{plan}', $plan->id, $uri), $method === 'get' ? [] : planPayload(['code' => 'new-code'])];
}

it('sends guests to the admin login', function (string $method, string $uri) {
    [$method, $uri, $data] = planRequest($method, $uri, $this->plan);

    $this->{$method}($uri, $data)->assertRedirect('/admin/login');
})->with('plan routes');

it('keeps tenant users out', function (string $method, string $uri) {
    [$method, $uri, $data] = planRequest($method, $uri, $this->plan);

    $this->actingAs(User::factory()->create(), 'web')->{$method}($uri, $data)->assertRedirect('/admin/login');
})->with('plan routes');

it('forbids admins without billing.manage', function (AdminRole $role, string $method, string $uri) {
    [$method, $uri, $data] = planRequest($method, $uri, $this->plan);

    $this->actingAs(planAdmin($role), 'admin')->{$method}($uri, $data)->assertForbidden();

    expect(Plan::withTrashed()->count())->toBe(1)
        ->and($this->plan->fresh()->trashed())->toBeFalse();
})->with([AdminRole::Sales, AdminRole::Support])->with('plan routes');

it('forbids inactive admins even with the right role', function () {
    $admin = Admin::factory()->owner()->inactive()->create();

    $this->actingAs($admin, 'admin')->get('/admin/plans')->assertRedirect('/admin/login');
});

it('lets owners and accounts admins in', function (AdminRole $role) {
    $this->actingAs(planAdmin($role), 'admin')->get('/admin/plans')->assertOk();
    $this->actingAs(planAdmin($role), 'admin')->get("/admin/plans/{$this->plan->id}")->assertOk();
    $this->actingAs(planAdmin($role), 'admin')->post('/admin/plans', planPayload(['code' => "by-{$role->value}"]))->assertRedirect();

    expect(Plan::query()->where('code', "by-{$role->value}")->exists())->toBeTrue();
})->with([AdminRole::Owner, AdminRole::Accounts]);

it('shares billing.manage in the admin abilities so the nav shows Plans', function (AdminRole $role, bool $visible) {
    $this->actingAs(planAdmin($role), 'admin')
        ->get('/admin')
        ->assertInertia(fn ($page) => $page->where('admin.abilities', fn ($abilities) => collect($abilities)->contains('billing.manage') === $visible));
})->with([
    'owner' => [AdminRole::Owner, true],
    'accounts' => [AdminRole::Accounts, true],
    'sales' => [AdminRole::Sales, false],
    'support' => [AdminRole::Support, false],
]);
