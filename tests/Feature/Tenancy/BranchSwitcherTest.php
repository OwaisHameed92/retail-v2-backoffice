<?php

use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\DeactivateBranch;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->company = $this->tenant('Khan Mini Mart', code: 'LDS');
    $this->bradford = app(AddBranch::class)->handle($this->company, new BranchDetails('BFD', 'Bradford'), 1);
    $this->leeds = $this->branchOf($this->company, 'LDS');
    $this->user = $this->ownerOf($this->company);
});

test('the top bar gets the company\'s active branches and "all branches" by default', function () {
    $closed = app(AddBranch::class)->handle($this->company, new BranchDetails('OLD', 'Old Town'));
    app(DeactivateBranch::class)->handle($closed);

    $this->actingAs($this->user)->get('/app')->assertInertia(fn ($page) => $page
        ->where('branches', [
            ['id' => $this->bradford->id, 'code' => 'BFD', 'name' => 'Bradford'],
            ['id' => $this->leeds->id, 'code' => 'LDS', 'name' => 'Leeds'],
        ])
        ->where('currentBranchId', null));
});

test('choosing a branch keeps it in the session and shares it', function () {
    $this->actingAs($this->user)->from('/app')->post('/app/branch/switch', ['branch_id' => $this->bradford->id])
        ->assertRedirect('/app')
        ->assertSessionHas('current_branch_id', $this->bradford->id);

    $this->actingAs($this->user)->get('/app')->assertInertia(fn ($page) => $page->where('currentBranchId', $this->bradford->id));
});

test('choosing all branches clears the choice', function () {
    $this->actingAs($this->user)->withSession(['current_branch_id' => $this->bradford->id])
        ->post('/app/branch/switch', ['branch_id' => null]);

    expect(session()->has('current_branch_id'))->toBeFalse();
});

test('any role can switch branch', function () {
    $staff = $this->addMember($this->company, CompanyRole::Staff);

    $this->actingAs($staff)->post('/app/branch/switch', ['branch_id' => $this->leeds->id])->assertSessionHas('current_branch_id', $this->leeds->id);
});

test('another company\'s branch cannot be chosen', function () {
    $other = $this->tenant('Bravo Mart', code: 'BRV');

    $this->actingAs($this->user)->post('/app/branch/switch', ['branch_id' => $this->branchOf($other, 'BRV')->id])
        ->assertSessionHasErrors(['branch_id' => 'Choose one of your active branches.']);

    expect(session()->has('current_branch_id'))->toBeFalse();
});

test('an inactive branch cannot be chosen', function () {
    app(DeactivateBranch::class)->handle($this->bradford);

    $this->actingAs($this->user)->post('/app/branch/switch', ['branch_id' => $this->bradford->id])->assertSessionHasErrors('branch_id');
});

test('a stale choice falls back to all branches', function () {
    app(DeactivateBranch::class)->handle($this->bradford);

    $this->actingAs($this->user)->withSession(['current_branch_id' => $this->bradford->id])->get('/app')
        ->assertInertia(fn ($page) => $page->where('currentBranchId', null));

    expect(session()->has('current_branch_id'))->toBeFalse();
});

test('switching company resets the branch to all branches', function () {
    $other = $this->tenant('Bravo Mart', code: 'BRV');
    $other->users()->attach($this->user->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);

    $this->actingAs($this->user)->withSession(['current_branch_id' => $this->bradford->id])
        ->post('/app/company/switch', ['company_id' => $other->id]);

    expect(session()->has('current_branch_id'))->toBeFalse();
    $this->actingAs($this->user)->get('/app')->assertInertia(fn ($page) => $page
        ->where('company.id', $other->id)
        ->where('branches.0.code', 'BRV')
        ->has('branches', 1));
});

test('guests cannot switch branch', function () {
    $this->post('/app/branch/switch', ['branch_id' => $this->leeds->id])->assertRedirect('/login');
});
