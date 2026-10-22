<?php

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Tests\Feature\Handheld\HandheldFixtures as H;

/*
 * Handheld (PDA) API phase 2, clarification 2: `409 TENANT_REQUIRED` lists `tenants: [{id, name}]`, only the
 * businesses where this email AND this password open a switched-on handheld account. A wrong password never lists
 * (or hints at) any business.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 09:00:00');
    $this->kirkgate = Company::factory()->create(['name' => 'Kirkgate Stores']);
    $this->aire = Company::factory()->create(['name' => 'Aire Valley Foods']);
    $this->calder = Company::factory()->create(['name' => 'Calder Mini Market']);

    foreach ([$this->kirkgate, $this->aire, $this->calder] as $company) {
        H::monthlyPlan($company);
    }

    $this->account = fn (Company $company, array $attributes = []) => H::user($company, Branch::factory()->forCompany($company)->create(), $attributes);
    $this->login = fn (array $body = []) => H::login($this, ['email' => 'stock1@kirkgate.test', 'password' => H::PASSWORD, ...$body]);
});

test('two businesses with the same email and password: 409 lists exactly those two, sorted by name', function () {
    ($this->account)($this->kirkgate);
    ($this->account)($this->aire);
    ($this->account)($this->calder, ['password' => 'a-different-password']);

    ($this->login)()->assertStatus(409)->assertJsonPath('success', false)->assertJsonPath('code', 'TENANT_REQUIRED')
        ->assertJsonPath('tenants', [['id' => $this->aire->id, 'name' => 'Aire Valley Foods'], ['id' => $this->kirkgate->id, 'name' => 'Kirkgate Stores']])
        ->assertJsonStructure(['traceId', 'retryAfterSeconds', 'rejectedKey']);

    ($this->login)(['tenant_id' => $this->aire->id])->assertOk()->assertJsonPath('tenant.id', $this->aire->id);
    ($this->login)(['tenant_id' => $this->calder->id])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');
});

test('a wrong password is 401 with no tenant list, whatever the email opens', function () {
    ($this->account)($this->kirkgate);
    ($this->account)($this->aire);

    $reply = ($this->login)(['password' => 'not-the-password'])->assertUnauthorized()->assertJsonPath('code', 'INVALID_CREDENTIALS');

    expect($reply->json())->not->toHaveKey('tenants')
        ->and($reply->getContent())->not->toContain('Kirkgate Stores')->not->toContain('Aire Valley Foods');
});

test('a switched-off account never counts: one switched-on match signs in, several are listed, none is 403 ACCOUNT_INACTIVE', function () {
    ($this->account)($this->kirkgate);
    ($this->account)($this->aire, ['is_active' => false]);

    ($this->login)()->assertOk()->assertJsonPath('tenant.id', $this->kirkgate->id);

    ($this->account)($this->calder);
    $listed = ($this->login)()->assertStatus(409)->assertJsonPath('tenants.*.id', [$this->calder->id, $this->kirkgate->id]);
    expect($listed->getContent())->not->toContain('Aire Valley Foods');

    HandheldUser::withoutCompanyScope()->update(['is_active' => false]);
    ($this->login)()->assertForbidden()->assertJsonPath('code', 'ACCOUNT_INACTIVE');
});
