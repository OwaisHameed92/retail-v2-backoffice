<?php

use App\Domain\Staff\Actions\AssignStaffFob;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\TillRolePermission;
use App\Domain\TillData\Models\TillUser;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Staff\StaffFixtures as Staff;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/** Module 4.5: the till staff screens (`/app/staff/*`, `company.can:staff.manage`). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    Staff::roles($this->company);
    $this->boss = Staff::member($this->company, 'Imran Khan', '7391', Staff::OWNER);
    $this->aisha = Staff::member($this->company, 'Aisha Patel', '4821', Staff::CASHIER, ['branch_ids' => [$this->sync->leeds->id]]);
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->new = ['name' => 'Tom Reed', 'role_id' => Staff::CASHIER, 'is_active' => true, 'simple_mode_override' => 'role',
        'rate_per_hour' => '11.44', 'branch_ids' => [$this->sync->bradford->id], 'pin' => '5820', 'pin_confirmation' => '5820'];
});

test('guests are sent to the login page', function () {
    $this->get('/app/staff')->assertRedirect('/login');
    $this->post('/app/staff', $this->new)->assertRedirect('/login');
    $this->put("/app/staff/{$this->aisha->id}/pin", ['pin' => '5930', 'pin_confirmation' => '5930'])->assertRedirect('/login');
    $this->get('/app/staff/roles')->assertRedirect('/login');
});

test('staff and accountants get 403 everywhere; nothing changes', function (CompanyRole $role) {
    $user = $this->memberOf($this->company, $role);
    $id = $this->aisha->id;

    $this->actingAs($user)->get('/app/staff')->assertForbidden();
    $this->actingAs($user)->get('/app/staff/create')->assertForbidden();
    $this->actingAs($user)->post('/app/staff', $this->new)->assertForbidden();
    $this->actingAs($user)->get("/app/staff/{$id}/edit")->assertForbidden();
    $this->actingAs($user)->put("/app/staff/{$id}", [...$this->new, 'pin' => null])->assertForbidden();
    $this->actingAs($user)->put("/app/staff/{$id}/pin", ['pin' => '5930', 'pin_confirmation' => '5930'])->assertForbidden();
    $this->actingAs($user)->put("/app/staff/{$id}/fob", ['rfid' => '04A1B2C3'])->assertForbidden();
    $this->actingAs($user)->delete("/app/staff/{$id}")->assertForbidden();
    $this->actingAs($user)->get('/app/staff/roles')->assertForbidden();
    $this->actingAs($user)->put('/app/staff/roles/'.Staff::MANAGER, ['permissions' => ['sale.refund']])->assertForbidden();

    expect(TillUser::withoutCompanyScope()->count())->toBe(2)
        ->and(TillRolePermission::withoutCompanyScope()->count())->toBe(0);
})->with([CompanyRole::Staff, CompanyRole::Accountant]);

test('a one-shop manager may look but not change what every shop uses', function () {
    $this->company->users()->updateExistingPivot($this->manager->id, ['branch_id' => $this->sync->leeds->id]);

    $this->actingAs($this->manager)->get('/app/staff')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canEdit', false));
    $this->actingAs($this->manager)->post('/app/staff', $this->new)->assertForbidden();
    $this->actingAs($this->manager)->put("/app/staff/{$this->aisha->id}/pin", ['pin' => '5930', 'pin_confirmation' => '5930'])->assertForbidden();
    $this->actingAs($this->manager)->delete("/app/staff/{$this->aisha->id}")->assertForbidden();
    $this->actingAs($this->manager)->put('/app/staff/roles/'.Staff::MANAGER, ['permissions' => []])->assertForbidden();
});

test('managers see the list with filters and no PIN hash or fob code', function () {
    app(AssignStaffFob::class)->handle($this->company, $this->aisha->id, '04A1B2C3');
    $hash = TillUser::withoutCompanyScope()->findOrFail($this->aisha->id)->pin_hash;

    $response = $this->actingAs($this->manager)->get('/app/staff?branch='.$this->sync->leeds->id);
    $response->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/staff/index')
        ->where('canEdit', true)
        ->where('counts', ['all' => 2, 'active' => 2])
        ->has('staff.data', 1)
        ->where('staff.data.0.name', 'Aisha Patel')
        ->where('staff.data.0.role', 'Cashier')
        ->where('staff.data.0.hasPin', true)
        ->where('staff.data.0.hasFob', true)
        ->where('staff.data.0.branches', ['Leeds Kirkgate'])
        ->has('options.roles', 3));

    expect($response->getContent())->not->toContain($hash)->not->toContain('04A1B2C3');

    $edit = $this->actingAs($this->manager)->get("/app/staff/{$this->aisha->id}/edit")->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('app/staff/form')->where('member.hasFob', true)->where('member.role_id', Staff::CASHIER));
    expect($edit->getContent())->not->toContain($hash)->not->toContain('04A1B2C3');
});

test('managers add, edit, set a PIN, give a fob and remove staff; PINs are never flashed', function () {
    $this->actingAs($this->manager)->post('/app/staff', $this->new)->assertRedirect('/app/staff')->assertSessionHas('success');
    $tom = TillUser::withoutCompanyScope()->where('name', 'Tom Reed')->sole();
    expect($tom->company_id)->toBe($this->company->id);

    $this->actingAs($this->manager)->put("/app/staff/{$tom->id}", [...$this->new, 'name' => 'Tom Reid', 'pin' => null, 'pin_confirmation' => null, 'simple_mode_override' => 'on'])
        ->assertRedirect("/app/staff/{$tom->id}/edit");
    expect($tom->fresh()->name)->toBe('Tom Reid')->and($tom->fresh()->simple_mode_override)->toBeTrue();

    $this->actingAs($this->manager)->from("/app/staff/{$tom->id}/edit")->put("/app/staff/{$tom->id}/pin", ['pin' => '4821', 'pin_confirmation' => '4821'])
        ->assertSessionHasErrors('pin')->assertSessionMissing('_old_input.pin');
    $this->actingAs($this->manager)->put("/app/staff/{$tom->id}/pin", ['pin' => '6048', 'pin_confirmation' => '6048'])->assertSessionHasNoErrors();
    $this->actingAs($this->manager)->put("/app/staff/{$tom->id}/fob", ['rfid' => '04A1B2C3'])->assertSessionHasNoErrors();
    expect($tom->fresh()->rfid)->toBe('04A1B2C3');

    $this->actingAs($this->manager)->delete("/app/staff/{$tom->id}")->assertRedirect('/app/staff');
    expect(TillUser::withoutCompanyScope()->withTrashed()->findOrFail($tom->id)->trashed())->toBeTrue();

    $this->actingAs($this->manager)->delete("/app/staff/{$this->boss->id}")->assertSessionHasErrors('status');
});

test('the role editor lists the catalogue and saves a role\'s permissions', function () {
    $this->actingAs($this->manager)->get('/app/staff/roles?role='.Staff::MANAGER)->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->component('app/staff/roles')
        ->where('selected', Staff::MANAGER)
        ->has('roles', 3)
        ->where('roles.0.isOwner', true)
        ->where('groups', fn ($groups) => collect($groups)->pluck('permissions')->flatten(1)->pluck('key')->contains('business.apply_all_shops')));

    $this->actingAs($this->manager)->put('/app/staff/roles/'.Staff::MANAGER, ['permissions' => ['business.apply_all_shops', 'sale.refund']])
        ->assertRedirect('/app/staff/roles?role='.Staff::MANAGER);

    expect(TillRolePermission::withoutCompanyScope()->where('role_id', Staff::MANAGER)->count())->toBe(2);
});

test('another business\'s staff and roles are not found and never listed', function () {
    $other = Company::factory()->create();
    $theirOwner = $this->memberOf($other, CompanyRole::Owner);
    $id = $this->aisha->id;

    $this->actingAs($theirOwner)->get('/app/staff')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('counts.all', 0)->has('staff.data', 0));
    $this->actingAs($theirOwner)->get("/app/staff/{$id}/edit")->assertNotFound();
    $this->actingAs($theirOwner)->put("/app/staff/{$id}", [...$this->new, 'pin' => null])->assertNotFound();
    $this->actingAs($theirOwner)->put("/app/staff/{$id}/pin", ['pin' => '5930', 'pin_confirmation' => '5930'])->assertNotFound();
    $this->actingAs($theirOwner)->put("/app/staff/{$id}/fob", ['rfid' => '04A1B2C3'])->assertNotFound();
    $this->actingAs($theirOwner)->delete("/app/staff/{$id}")->assertNotFound();
    $this->actingAs($theirOwner)->put('/app/staff/roles/'.Staff::MANAGER, ['permissions' => []])->assertNotFound();
    $this->actingAs($theirOwner)->post('/app/staff', $this->new)->assertSessionHasErrors('role_id');

    expect(TillUser::withoutCompanyScope()->findOrFail($id)->name)->toBe('Aisha Patel');
});
