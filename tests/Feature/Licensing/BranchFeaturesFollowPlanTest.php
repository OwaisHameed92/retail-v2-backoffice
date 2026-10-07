<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Actions\UpdateBranchLicence;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Plans\Models\Plan;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddBranch;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Fix 2026-10-07: a branch's features follow its plan (`licence_features` null) unless staff really chose others.
 * Equal lists are stored as null on every save path, compared with the plan as it is at save time.
 *
 * Stale forms: a list that differs from the plan at save time is treated as a deliberate choice and stored. The admin
 * forms now send `features: null` unless "Customise for this shop" is ticked, so a form loaded before a plan edit
 * sends null and the branch follows the plan as it is now.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 09:00:00', 'UTC'));
});

/** The wizard's fields for a one-shop business on a plan; `$licence` adds or overrides licence form fields. */
function wizardForm(Plan $plan, array $licence = []): array
{
    return [
        'name' => 'Corner Shop', 'business_type' => 'Newsagent', 'town' => 'York', 'postcode' => 'YO1 7HH', 'status' => 'trial',
        'branch_code' => 'YRK', 'branch_name' => 'York', 'branch_nation' => 'england', 'tills' => 1, 'plan_id' => $plan->id,
        'owner_name' => 'Sam Patel', 'owner_email' => 'sam@corner.test', 'max_registers' => 1, 'kind' => 'trial',
    ] + $licence;
}

function cornerShop(): Branch
{
    $company = Company::query()->where('name', 'Corner Shop')->sole();

    return Branch::withoutCompanyScope()->where('company_id', $company->id)->sole();
}

/** @return list<list<Feature>> each live key's features */
function keyFeatures(Branch $branch): array
{
    return Licence::withoutCompanyScope()->live()->where('branch_id', $branch->id)->get()->map(fn (Licence $l) => $l->features->all())->all();
}

function setupOnlyPlan(): Plan
{
    return Plan::factory()->create(['name' => 'Setup only', 'code' => 'setup', 'features' => [Feature::Loyalty], 'sort_order' => 5]);
}

test('the wizard with untouched features stores null, also when it sends the plan\'s own list', function (?array $features) {
    $plan = $this->standardPlan();

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')
        ->post('/admin/tenants', wizardForm($plan, $features === null ? [] : ['features' => $features]))
        ->assertSessionHasNoErrors()->assertRedirect();

    $branch = cornerShop();
    expect($branch->licence_features)->toBeNull()
        ->and(keyFeatures($branch))->toBe([[Feature::Loyalty, Feature::Promotions]]);
})->with([
    'features absent' => [null],
    'the plan\'s list in another order' => [['promotions', 'loyalty']],
]);

test('the wizard with customised features stores the list', function () {
    $plan = $this->standardPlan();

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')
        ->post('/admin/tenants', wizardForm($plan, ['features' => ['loyalty', 'second_screen']]))->assertSessionHasNoErrors();

    $branch = cornerShop();
    expect($branch->licence_features)->toBe(['loyalty', 'second_screen'])
        ->and(keyFeatures($branch))->toBe([[Feature::Loyalty, Feature::SecondScreen]]);
});

test('a stale wizard form: null follows the plan as it is now; an old list that differs is kept as a deliberate choice', function () {
    $plan = setupOnlyPlan();
    $plan->forceFill(['features' => [Feature::Loyalty, Feature::CloudSync]])->save();
    $loaded = ['cloud_sync', 'loyalty'];
    // The plan loses cloud sync in another tab after the form was loaded.
    $plan->forceFill(['features' => [Feature::Loyalty]])->save();
    $admin = $this->admin(AdminRole::Sales);

    // Today's form sends null unless "Customise for this shop" was ticked.
    $this->actingAs($admin, 'admin')->post('/admin/tenants', wizardForm($plan, ['features' => null]))->assertSessionHasNoErrors();
    $branch = cornerShop();
    expect($branch->licence_features)->toBeNull()
        ->and(keyFeatures($branch))->toBe([[Feature::Loyalty]]);

    // An old form posting the plan's former list: it differs from the plan now, so it is stored as chosen.
    $this->post('/admin/tenants', array_merge(wizardForm($plan, ['features' => $loaded]), [
        'name' => 'Old Form Shop', 'branch_code' => 'OLD', 'owner_email' => 'old@corner.test',
    ]))->assertSessionHasNoErrors();
    $old = Branch::withoutCompanyScope()->where('code', 'OLD')->sole();
    expect($old->licence_features)->toBe(['loyalty', 'cloud_sync'])
        ->and(keyFeatures($old))->toBe([[Feature::Loyalty, Feature::CloudSync]]);
});

test('trial approval without features or with the plan\'s stores null for every shop', function (?array $features) {
    $this->standardPlan();
    $lead = Lead::factory()->create(['business_type' => 'grocery', 'town' => 'Leeds', 'postcode' => 'LS1 1AA']);

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->post("/admin/leads/{$lead->id}/approve", [
        'shops' => [['name' => 'Leeds', 'code' => 'LDS', 'nation' => 'england', 'tills' => 1], ['name' => 'York', 'code' => 'YRK', 'nation' => 'england', 'tills' => 1]],
        'kind' => 'trial', 'features' => $features,
    ])->assertSessionHasNoErrors();

    $company = $lead->fresh()->company;
    expect($this->branchOf($company, 'LDS')->licence_features)->toBeNull()
        ->and($this->branchOf($company, 'YRK')->licence_features)->toBeNull();
})->with([
    'null' => [null],
    'the plan\'s list' => [['loyalty', 'promotions']],
]);

test('a plan edit reaches branches that follow it, not those with their own features', function () {
    $company = $this->licensedTenant(tills: 1);
    $follows = $this->allowTills($this->branchOf($company));
    $own = app(AddBranch::class)->handle($company, new BranchDetails(code: 'BFD', name: 'Bradford'), 1, new BranchLicenceSettings(maxRegisters: 3, features: [Feature::Purchasing]));
    $this->allowTills($own);

    $this->standardPlan()->forceFill(['features' => [Feature::Loyalty, Feature::Promotions, Feature::SecondScreen]])->save();

    app(AddRegister::class)->handle($follows->fresh());
    app(AddRegister::class)->handle($own->fresh());
    expect($this->licenceOf($this->registerOf($follows, '02'))->features->all())->toBe([Feature::Loyalty, Feature::Promotions, Feature::SecondScreen])
        ->and($this->licenceOf($this->registerOf($own, '02'))->features->all())->toBe([Feature::Purchasing]);

    // Saving the following branch's settings (features untouched) puts the plan's current features on every key.
    app(UpdateBranchLicence::class)->handle($follows->fresh(), BranchLicenceSettings::of($follows->fresh())->withMaxRegisters(5));
    expect($follows->fresh()->licence_features)->toBeNull()
        ->and(keyFeatures($follows))->each->toBe([Feature::Loyalty, Feature::Promotions, Feature::SecondScreen]);
});

test('saving the plan\'s own list in the licence dialog or adding a branch stores null', function () {
    $company = $this->licensedTenant(tills: 1);
    $branch = $this->branchOf($company);
    $this->actingAs($this->admin(AdminRole::Support), 'admin');

    $this->put("/admin/tenants/{$company->id}/branches/{$branch->id}/licence", ['max_registers' => 3, 'kind' => 'trial', 'features' => ['promotions', 'loyalty']])
        ->assertSessionHasNoErrors();
    expect($branch->fresh()->licence_features)->toBeNull();

    $added = app(AddBranch::class)->handle($company, new BranchDetails(code: 'BFD', name: 'Bradford'), 1, new BranchLicenceSettings(features: [Feature::Promotions, Feature::Loyalty, Feature::MultiBranch]));
    expect($added->licence_features)->toBeNull();
});

test('the tenant and licence pages flag custom features; "Use the plan\'s features" resets them', function () {
    $company = $this->licensedTenant(tills: 2);
    $branch = $this->branchOf($company);
    app(UpdateBranchLicence::class)->handle($branch, new BranchLicenceSettings(maxRegisters: 2, features: [Feature::SecondScreen]));
    $licence = $this->firstLicence($company);
    $support = $this->admin(AdminRole::Support);

    $this->actingAs($support, 'admin')->get("/admin/tenants/{$company->id}")->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('branches.0.licence.featuresCustom', true)
            ->where('branches.0.licence.planFeatures', ['loyalty', 'promotions']));
    $this->get("/admin/licences/{$licence->id}")->assertOk()->assertInertia(fn ($page) => $page->where('licence.branchFeaturesCustom', true));

    $this->delete("/admin/tenants/{$company->id}/branches/{$branch->id}/licence/features")
        ->assertSessionHasNoErrors()->assertSessionHas('success');

    expect($branch->fresh()->licence_features)->toBeNull()
        ->and(keyFeatures($branch))->toBe([[Feature::Loyalty, Feature::Promotions], [Feature::Loyalty, Feature::Promotions]])
        ->and(AuditLog::query()->where('action', 'branch.licence_updated')->latest('id')->first()?->after['features'])->toBeNull();

    $this->get("/admin/tenants/{$company->id}")->assertInertia(fn ($page) => $page->where('branches.0.licence.featuresCustom', false));
    $this->get("/admin/licences/{$licence->id}")->assertInertia(fn ($page) => $page->where('licence.branchFeaturesCustom', false));
});

test('the reset needs licences.manage and stays inside the company', function () {
    $mine = $this->licensedTenant('Mine', 1, 'MIN');
    $other = $this->licensedTenant('Other', 1, 'OTH');
    $theirs = $this->branchOf($other, 'OTH');
    app(UpdateBranchLicence::class)->handle($theirs, new BranchLicenceSettings(features: [Feature::SecondScreen]));

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->delete("/admin/tenants/{$other->id}/branches/{$theirs->id}/licence/features")->assertForbidden();
    $this->actingAs($this->admin(AdminRole::Owner), 'admin')
        ->delete("/admin/tenants/{$mine->id}/branches/{$theirs->id}/licence/features")->assertNotFound();
    auth('admin')->logout();
    $this->delete("/admin/tenants/{$other->id}/branches/{$theirs->id}/licence/features")->assertRedirect();

    expect($theirs->fresh()->licence_features)->toBe(['second_screen']);
});

test('licences:features-follow-plan: dry run lists, a run lets equal lists follow the plan, differing ones stay; idempotent', function () {
    $company = $this->licensedTenant('Khan', 1, 'KHN');
    $other = $this->licensedTenant('Other', 1, 'OTH');
    // Rows as the old forms left them: a copy of the plan's list (one with multi-branch), and a real choice.
    $copy = $this->branchOf($company, 'KHN');
    $copy->forceFill(['licence_features' => ['promotions', 'loyalty', 'multi_branch']])->saveQuietly();
    $chosen = $this->branchOf($other, 'OTH');
    $chosen->forceFill(['licence_features' => ['second_screen']])->saveQuietly();
    $before = keyFeatures($chosen);

    $this->artisan('licences:features-follow-plan', ['--dry-run' => true])
        ->expectsOutputToContain('would follow the plan')
        ->expectsOutputToContain('differs (kept)')
        ->expectsOutputToContain('Would set 1 to follow the plan; 1 differ.')
        ->assertSuccessful();
    expect($copy->fresh()->licence_features)->not->toBeNull();

    $this->artisan('licences:features-follow-plan')->expectsOutputToContain('Set 1 to follow the plan; 1 differ.')->assertSuccessful();
    expect($copy->fresh()->licence_features)->toBeNull()
        ->and(keyFeatures($copy))->toBe([[Feature::Loyalty, Feature::Promotions]])
        ->and($chosen->fresh()->licence_features)->toBe(['second_screen'])
        ->and(keyFeatures($chosen))->toBe($before);

    $this->artisan('licences:features-follow-plan', ['--company' => $company->id])
        ->expectsOutput('Every branch already follows its plan\'s features. Nothing to change.')->assertSuccessful();
});

test('licences:features-follow-plan --reset needs --company and resets only that business', function () {
    $company = $this->licensedTenant('Khan', 1, 'KHN');
    $other = $this->licensedTenant('Other', 1, 'OTH');
    app(UpdateBranchLicence::class)->handle($this->branchOf($company, 'KHN'), new BranchLicenceSettings(features: [Feature::SecondScreen]));
    app(UpdateBranchLicence::class)->handle($this->branchOf($other, 'OTH'), new BranchLicenceSettings(features: [Feature::Purchasing]));

    $this->artisan('licences:features-follow-plan', ['--reset' => true])->assertFailed();
    $this->artisan('licences:features-follow-plan', ['--company' => 'nope'])->assertFailed();
    $this->artisan('licences:features-follow-plan', ['--company' => $company->id, '--reset' => true, '--dry-run' => true])
        ->expectsOutputToContain('would reset to the plan')->assertSuccessful();
    expect($this->branchOf($company, 'KHN')->licence_features)->toBe(['second_screen']);

    $this->artisan('licences:features-follow-plan', ['--company' => $company->id, '--reset' => true])
        ->expectsOutputToContain('reset to the plan')->assertSuccessful();

    expect($this->branchOf($company, 'KHN')->licence_features)->toBeNull()
        ->and(keyFeatures($this->branchOf($company, 'KHN')))->toBe([[Feature::Loyalty, Feature::Promotions]])
        ->and($this->branchOf($other, 'OTH')->licence_features)->toBe(['purchasing']);
});
