<?php

use App\Domain\Billing\GoCardless\Data\DirectDebitData;
use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldAccess;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Models\Plan;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\PortalUsers\PortalUsersHelpers as P;

/*
 * Owner 2026-10-21: the handheld (PDA) app is only for businesses on a monthly or yearly plan
 * (`plans.includes_handheld`). The API answers 403 PLAN_NOT_INCLUDED at login and on every call; tokens are kept and
 * work again once the plan includes it; the portal shows a notice and refuses Add server side.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->travelTo('2026-10-21 09:00:00');
    $this->company = Company::factory()->create(['name' => 'Kirkgate Stores']);
    $this->leeds = Branch::factory()->forCompany($this->company)->create(['code' => 'LDS', 'name' => 'Leeds Kirkgate']);
    $this->monthly = H::monthlyPlan($this->company);
    $this->setupOnly = H::setupOnlyPlan();
    $this->user = H::user($this->company, $this->leeds);
});

function assertPlanNotIncluded(TestResponse $response): void
{
    $response->assertForbidden()->assertJsonPath('success', false)->assertJsonPath('code', 'PLAN_NOT_INCLUDED')
        ->assertJsonPath('message', 'The handheld app is part of the monthly plans. Ask Switch & Save to upgrade.')
        ->assertJsonStructure(['success', 'code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);
}

function putOnPlan(Company $company, Plan $plan): void
{
    $company->forceFill(['plan_id' => $plan->id])->saveQuietly();
}

test('a business on a monthly plan signs in and calls the API', function () {
    $token = H::token($this, $this->user);

    $this->getJson('/api/pda/me', H::headers($token))->assertOk()->assertJsonPath('tenant.id', $this->company->id);
    expect(HandheldAccess::allowed($this->company))->toBeTrue();
});

test('a setup-only business is refused at login with PLAN_NOT_INCLUDED and no token is made', function () {
    putOnPlan($this->company, $this->setupOnly);

    assertPlanNotIncluded(H::login($this, ['email' => $this->user->email, 'password' => H::PASSWORD]));
    expect(HandheldToken::withoutCompanyScope()->count())->toBe(0)->and(HandheldAccess::allowed($this->company))->toBeFalse();

    // A wrong password still answers 401: the plan never tells a stranger the account exists.
    H::login($this, ['email' => $this->user->email, 'password' => 'wrong-password'])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

test('tokens stop working while the plan does not include it and work again after an upgrade, never deleted', function () {
    $token = H::token($this, $this->user);

    putOnPlan($this->company, $this->setupOnly);
    assertPlanNotIncluded($this->getJson('/api/pda/me', H::headers($token)));
    assertPlanNotIncluded($this->getJson('/api/pda/products', H::headers($token)));
    expect(HandheldToken::withoutCompanyScope()->whereNull('revoked_at')->count())->toBe(1)
        ->and($this->user->fresh()->is_active)->toBeTrue();

    putOnPlan($this->company, $this->monthly);
    $this->getJson('/api/pda/me', H::headers($token))->assertOk();
});

test('the owner switching the plan setting off and on changes access at the next call', function () {
    $token = H::token($this, $this->user);

    $this->monthly->forceFill(['includes_handheld' => false])->save();
    assertPlanNotIncluded($this->getJson('/api/pda/me', H::headers($token)));

    $this->setupOnly->forceFill(['includes_handheld' => true])->save();
    putOnPlan($this->company, $this->setupOnly);
    $this->getJson('/api/pda/me', H::headers($token))->assertOk();
});

test('the plan of the live tills wins over companies.plan_id, as billing reads it', function () {
    $register = Register::factory()->forBranch($this->leeds)->create();
    $licence = Licence::factory()->forRegister($register)->onPlan($this->setupOnly)->create();

    // companies.plan_id says monthly, but the live till is on setup only.
    expect(HandheldAccess::plan($this->company)?->id)->toBe($this->setupOnly->id);
    assertPlanNotIncluded(H::login($this, ['email' => $this->user->email, 'password' => H::PASSWORD]));

    // And the other way round: company plan setup only, live till monthly.
    putOnPlan($this->company, $this->setupOnly);
    $licence->forceFill(['plan_id' => $this->monthly->id])->save();
    expect(HandheldAccess::plan($this->company)?->id)->toBe($this->monthly->id);
    H::login($this, ['email' => $this->user->email, 'password' => H::PASSWORD])->assertOk();
});

test('one business\'s plan never decides another\'s access', function () {
    $other = Company::factory()->create(['name' => 'Other Stores']);
    $otherShop = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    putOnPlan($other, $this->setupOnly);
    $theirs = H::user($other, $otherShop, ['email' => 'their@other.test']);

    assertPlanNotIncluded(H::login($this, ['email' => $theirs->email, 'password' => H::PASSWORD]));
    H::login($this, ['email' => $this->user->email, 'password' => H::PASSWORD])->assertOk()->assertJsonPath('tenant.id', $this->company->id);
});

test('the portal screen shows the plan state and refuses Add server side when not included', function () {
    $owner = P::member($this->company, CompanyRole::Owner);
    $new = ['name' => 'Stock room 2', 'email' => 'stock2@kirkgate.test', 'branch_id' => $this->leeds->id, 'can_change_prices' => false,
        'password' => 'a-long-enough-password', 'password_confirmation' => 'a-long-enough-password'];

    $this->actingAs($owner)->get('/app/handheld-users')->assertOk()->assertInertia(fn (Assert $page) => $page->where('included', true));

    putOnPlan($this->company, $this->setupOnly);
    $this->actingAs($owner)->get('/app/handheld-users')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/handheld-users/index')->where('included', false)->has('users', 1));

    $this->actingAs($owner)->post('/app/handheld-users', $new)->assertSessionHasErrors('plan');
    expect(HandheldUser::withoutCompanyScope()->count())->toBe(1);

    // Existing users stay manageable: switching off still works.
    $this->actingAs($owner)->post("/app/handheld-users/{$this->user->id}/deactivate")->assertSessionHas('success');
    expect($this->user->fresh()->is_active)->toBeFalse();
});

test('the admin Billing tab says whether the handheld app is on the business\'s plan', function () {
    expect(DirectDebitData::for($this->company)['handheldIncluded'])->toBeTrue();

    putOnPlan($this->company, $this->setupOnly);
    expect(DirectDebitData::for($this->company->fresh())['handheldIncluded'])->toBeFalse();
});
