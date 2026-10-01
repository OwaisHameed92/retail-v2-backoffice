<?php

use App\Domain\Admin\Models\Admin;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\PortalUsers\PortalUsersHelpers as H;

/*
 * /app/activity: a business's own audit entries, for the owner (audit.view).
 */

beforeEach(function () {
    $this->withoutVite();
    $this->khan = Company::factory()->create(['name' => 'Khan Mini Mart', 'status' => 'active']);
    $this->patel = Company::factory()->create(['name' => 'Patel News', 'status' => 'active']);
    $this->owner = H::member($this->khan, CompanyRole::Owner, attributes: ['name' => 'Aisha Khan']);
    $this->manager = H::member($this->khan, CompanyRole::Manager, attributes: ['name' => 'Bilal Ahmed']);
    $this->patelOwner = H::member($this->patel, CompanyRole::Owner);
    $this->admin = Admin::factory()->create(['name' => 'Sam Support']);

    $this->mine = AuditLog::query()->create([
        'company_id' => $this->khan->id, 'action' => 'product.updated', 'actor_type' => $this->manager->getMorphClass(),
        'actor_id' => (string) $this->manager->id, 'subject_type' => 'App\\Product', 'subject_id' => 'P1',
        'before' => ['price' => '1.00'], 'after' => ['price' => '1.20'], 'ip' => '203.0.113.7',
    ]);
    $this->byStaff = AuditLog::query()->create([
        'company_id' => $this->khan->id, 'action' => 'company.suspended', 'actor_type' => $this->admin->getMorphClass(),
        'actor_id' => $this->admin->id, 'ip' => '198.51.100.1', 'user_agent' => 'Admin browser',
    ]);
    $this->theirs = AuditLog::query()->create([
        'company_id' => $this->patel->id, 'action' => 'product.deleted', 'actor_type' => $this->patelOwner->getMorphClass(),
        'actor_id' => (string) $this->patelOwner->id,
    ]);
    $this->global = AuditLog::query()->create(['company_id' => null, 'action' => 'plan.updated']);
});

test('the owner sees only their own business\'s entries', function () {
    $this->actingAs($this->owner)->get('/app/activity')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/activity/index')
        ->has('entries.data', 2)
        ->where('entries.data', fn ($rows) => collect($rows)->pluck('id')->sort()->values()->all() === collect([$this->mine->id, $this->byStaff->id])->sort()->values()->all())
        ->where('options.actions', fn ($actions) => ! collect($actions)->pluck('value')->contains('product.deleted') && ! collect($actions)->pluck('value')->contains('plan.updated'))
        ->where('options.actors', fn ($actors) => collect($actors)->pluck('label')->sort()->values()->all() === ['Aisha Khan', 'Bilal Ahmed'])
        ->where('options.company', null));
});

test('another business\'s filters cannot reach across: company and record filters stay inside the business', function () {
    $rows = fn (string $query) => $this->actingAs($this->owner)->get('/app/activity'.$query)->assertOk()->inertiaProps('entries.data');

    expect($rows('?company='.$this->patel->id))->toHaveCount(2)
        ->and($rows('?action=product.deleted'))->toBe([])
        ->and($rows('?actor=user:'.$this->patelOwner->id))->toBe([])
        ->and(collect($rows('?after=7ZZZZZZZZZZZZZZZZZZZZZZZZZ'))->pluck('id')->all())->not->toContain($this->theirs->id);

    $csv = $this->get('/app/activity/export')->assertOk()->streamedContent();
    expect($csv)->toContain('Product updated')->and($csv)->not->toContain('Product deleted')->and($csv)->not->toContain('Plan updated');
});

test('Switch & Save staff show as the company, without their name, IP or browser', function () {
    $this->actingAs($this->owner)->get('/app/activity?actor=staff')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 1)
        ->where('entries.data.0.actor.name', 'Switch & Save')
        ->where('entries.data.0.actor.type', 'staff')
        ->where('entries.data.0.actor.id', null)
        ->where('entries.data.0.ip', null)
        ->where('entries.data.0.userAgent', null)
        ->where('entries.data.0.company', null));

    $csv = $this->get('/app/activity/export')->streamedContent();
    expect($csv)->not->toContain('Sam Support')->and($csv)->not->toContain('198.51.100.1')->and($csv)->not->toContain('Business');
});

test('a person filter and the diff of the business\'s own entries', function () {
    $this->actingAs($this->owner)->get('/app/activity?actor=user:'.$this->manager->id)->assertOk()->assertInertia(fn (Assert $page) => $page
        ->has('entries.data', 1)
        ->where('entries.data.0.actor.name', 'Bilal Ahmed')
        ->where('entries.data.0.ip', '203.0.113.7')
        ->where('entries.data.0.changes.0', ['field' => 'price', 'label' => 'Price', 'before' => '1.00', 'after' => '1.20']));
});

test('only the owner has audit.view: managers and other roles get 403', function (CompanyRole $role) {
    $user = H::member($this->khan, $role);

    $this->actingAs($user)->get('/app/activity')->assertForbidden();
    $this->get('/app/activity/export')->assertForbidden();
})->with([CompanyRole::Manager, CompanyRole::Accountant, CompanyRole::Staff]);

test('guests are sent to the sign-in', function () {
    $this->get('/app/activity')->assertRedirect(route('login'));
    $this->get('/app/activity/export')->assertRedirect(route('login'));
});

test('the export is audited for the business', function () {
    $this->actingAs($this->owner)->get('/app/activity/export?action=product.*')->assertOk()->streamedContent();

    $entry = AuditLog::query()->where('action', 'audit_log.exported')->sole();
    expect($entry->company_id)->toBe($this->khan->id)->and($entry->meta)->toBe(['action' => 'product.*']);
});
