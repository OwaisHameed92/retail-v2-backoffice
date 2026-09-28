<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
use App\Domain\Mail\Mailables\LicenceKeyMail;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Module 1.11 over HTTP: the licence form on the tenant page, the wizard, lead approval and the licence page.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-05 09:00:00', 'UTC'));
});

test('support saves a branch\'s licence settings and the company\'s branch limits', function () {
    $company = $this->licensedTenant();
    $branch = $this->branchOf($company);
    $this->actingAs($this->admin(AdminRole::Support), 'admin');

    $this->put("/admin/tenants/{$company->id}/branches/{$branch->id}/licence", [
        'max_registers' => 4, 'kind' => 'full', 'length' => 12, 'length_unit' => 'months', 'valid_from' => '2026-10-01', 'features' => ['second_screen', 'promotions'],
    ])->assertSessionHasNoErrors()->assertSessionHas('success');

    $branch->refresh();
    expect($branch->max_registers)->toBe(4)
        ->and($branch->licence_kind)->toBe(TokenKind::Full)
        ->and($branch->licence_valid_from?->toIso8601String())->toBe('2026-09-30T23:00:00+00:00')
        ->and($branch->licence_features)->toBe(['promotions', 'second_screen']);

    $this->put("/admin/tenants/{$company->id}/branches/{$branch->id}/licence", ['max_registers' => 4, 'kind' => 'trial', 'features' => ['multiBranch']])
        ->assertSessionHasErrors('features.0');
    $this->put("/admin/tenants/{$company->id}/branches/{$branch->id}/licence", ['max_registers' => 4, 'kind' => 'full'])
        ->assertSessionHasErrors(['length' => 'A full licence needs a length, for example 1 year.']);

    $this->put("/admin/tenants/{$company->id}/branch-limits", ['multi_branch' => true, 'max_branches' => 3])->assertSessionHas('success');
    expect($company->fresh()->max_branches)->toBe(3);
});

test('the tenant page shows the licence form with in use / allowed', function () {
    $company = $this->licensedTenant(tills: 2);

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->get("/admin/tenants/{$company->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('branches.0.licence.maxRegisters', 2)
            ->where('branches.0.licence.keysInUse', 2)
            ->where('branches.0.licence.tillsInUse', 2)
            ->where('branchLimits.branchesInUse', 1)
            ->where('branchLimits.branchesAllowed', 10)
            ->has('licenceOptions.features', count(Feature::cases()) - 1)
            ->where('licenceOptions.features.0.tillName', 'loyalty')
            ->has('licenceOptions.businessTypes', count(BusinessType::cases())));
});

test('one company\'s branch cannot be changed through another company', function () {
    $mine = $this->licensedTenant('Mine', 1, 'MIN');
    $other = $this->licensedTenant('Other', 1, 'OTH');
    $theirs = $this->branchOf($other, 'OTH');

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')
        ->put("/admin/tenants/{$mine->id}/branches/{$theirs->id}/licence", ['max_registers' => 9, 'kind' => 'trial'])
        ->assertNotFound();

    expect($theirs->fresh()->max_registers)->toBe(1);
});

test('the wizard creates the tenant with its licence form', function () {
    $plan = $this->standardPlan();

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->post('/admin/tenants', [
        'name' => 'Corner Shop', 'business_type' => 'Newsagent', 'town' => 'York', 'postcode' => 'yo1 7hh', 'receipt_footer' => 'Ta!',
        'status' => 'trial', 'branch_code' => 'YRK', 'branch_name' => 'York', 'branch_nation' => 'england', 'tills' => 2,
        'plan_id' => $plan->id, 'owner_name' => 'Sam Patel', 'owner_email' => 'sam@corner.test',
        'max_registers' => 3, 'kind' => 'trial', 'length' => 14, 'length_unit' => 'days', 'features' => ['second_screen'],
        'multi_branch' => true, 'max_branches' => 2,
    ])->assertSessionHasNoErrors()->assertRedirect();

    $company = Company::query()->where('name', 'Corner Shop')->sole();
    $branch = $this->branchOf($company, 'YRK');

    expect($company->business_type)->toBe(BusinessType::Newsagent)
        ->and($company->postcode)->toBe('YO1 7HH')
        ->and($company->owner_name)->toBe('Sam Patel')
        ->and([$company->multi_branch, $company->max_branches])->toBe([true, 2])
        ->and([$branch->max_registers, $branch->licence_length, $branch->licence_features])->toBe([3, 14, ['second_screen']])
        ->and(Licence::withoutCompanyScope()->where('company_id', $company->id)->get()->every(fn (Licence $l) => $l->features->all() === [Feature::SecondScreen]))->toBeTrue();

    $this->post('/admin/tenants', ['name' => 'X', 'status' => 'trial', 'branch_code' => 'XX', 'branch_name' => 'X', 'branch_nation' => 'england', 'tills' => 3, 'owner_name' => 'X', 'owner_email' => 'x@x.test', 'max_registers' => 2, 'kind' => 'trial'])
        ->assertSessionHasErrors('max_registers');
});

test('lead approval passes tills allowed, kind, length and features', function () {
    $this->standardPlan();
    $lead = Lead::factory()->create(['business_type' => 'grocery', 'town' => 'Leeds', 'postcode' => 'LS1 1AA']);

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->post("/admin/leads/{$lead->id}/approve", [
        'shops' => [['name' => 'Leeds', 'code' => 'LDS', 'nation' => 'england', 'tills' => 1, 'tills_allowed' => 3], ['name' => 'York', 'code' => 'YRK', 'nation' => 'england', 'tills' => 2]],
        'kind' => 'full', 'length' => 1, 'length_unit' => 'years', 'features' => ['promotions'],
    ])->assertSessionHasNoErrors();

    $company = $lead->fresh()->company;
    expect($company->business_type)->toBe(BusinessType::GroceryHalalButcher)
        ->and([$company->multi_branch, $company->max_branches])->toBe([true, 2])
        ->and($this->branchOf($company, 'LDS')->max_registers)->toBe(3)
        ->and($this->branchOf($company, 'YRK')->max_registers)->toBe(2)
        ->and($this->branchOf($company, 'YRK')->licence_kind)->toBe(TokenKind::Full);
});

test('resend key e-mail reissues the key and emails the owner; activate-by can be extended', function () {
    $company = $this->licensedTenant(tills: 1);
    $licence = $this->firstLicence($company);
    $oldHash = $licence->key_hash;
    $this->actingAs($this->admin(AdminRole::Support), 'admin');

    $this->get("/admin/licences/{$licence->id}")->assertOk()
        ->assertInertia(fn ($page) => $page->where('licence.activateBy', '2026-11-04T09:00:00+00:00')->where('licence.seat', ['position' => 1, 'allowed' => 1, 'keysInUse' => 1, 'activated' => 0]));

    $this->post("/admin/licences/{$licence->id}/resend")->assertSessionHas('success');
    expect($licence->fresh()->key_hash)->not->toBe($oldHash);
    Mail::assertQueued(LicenceKeyMail::class, 1);

    $this->put("/admin/licences/{$licence->id}/activate-by", ['activate_by' => '2026-12-24'])->assertSessionHas('success');
    expect($licence->fresh()->activate_by?->toIso8601String())->toBe('2026-12-24T23:59:59+00:00');

    $this->put("/admin/licences/{$licence->id}/activate-by", ['activate_by' => 'soon'])->assertSessionHasErrors('activate_by');
});

test('business details for the key are edited on the tenant and branch forms', function () {
    $company = $this->licensedTenant();
    $branch = $this->branchOf($company);
    $this->actingAs($this->admin(AdminRole::Sales), 'admin');

    $this->put("/admin/tenants/{$company->id}", ['name' => $company->name, 'business_type' => 'Pharmacy', 'owner_name' => 'Dr Khan', 'postcode' => 'nope'])
        ->assertSessionHasErrors('postcode');
    $this->put("/admin/tenants/{$company->id}", ['name' => $company->name, 'business_type' => 'Pharmacy', 'owner_name' => 'Dr Khan', 'town' => 'Leeds'])
        ->assertSessionHasNoErrors();
    $this->put("/admin/tenants/{$company->id}/branches/{$branch->id}", ['code' => 'LDS', 'name' => 'Leeds', 'nation' => 'england', 'town' => 'Leeds', 'postcode' => 'ls1 6ab', 'receipt_footer' => 'Thanks'])
        ->assertSessionHasNoErrors();

    expect($company->fresh()->business_type)->toBe(BusinessType::Pharmacy)
        ->and($company->fresh()->owner_name)->toBe('Dr Khan')
        ->and($branch->fresh()->only(['town', 'postcode', 'receipt_footer']))->toBe(['town' => 'Leeds', 'postcode' => 'LS1 6AB', 'receipt_footer' => 'Thanks']);
});
