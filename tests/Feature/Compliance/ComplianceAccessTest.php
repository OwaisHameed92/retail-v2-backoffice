<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Compliance\ComplianceFixtures as F;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.7: who may see Compliance (compliance.view: owner, manager, accountant) and raise recalls
 * (compliance.manage: owner, a manager of every shop), a one-shop user's own shop only, and one business never
 * seeing or changing another's rows.
 */

const COMPLIANCE_PAGES = ['/app/compliance', '/app/compliance/age-checks', '/app/compliance/incidents', '/app/compliance/training', '/app/compliance/diary', '/app/compliance/licences', '/app/compliance/recalls', '/app/compliance/exceptions'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    $id = $this->company->id;
    F::staff($id);
    F::refusal($id, 'LEEDS', TillFixtures::LEEDS, F::ALI, '2026-10-14 10:00:00');
    F::refusal($id, 'BRAD', TillFixtures::BRADFORD, F::BEA, '2026-10-14 10:00:00');
    F::row('incident_reports', ['id' => F::id('ILEEDS'), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'occurred_at' => '2026-10-14 09:00:00', 'category' => 'Theft', 'description' => 'Two bottles taken']);
    F::row('training_records', ['id' => F::id('TLEEDS'), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'user_id' => F::ALI, 'topic' => 'Challenge 25', 'trained_on' => '2026-01-01', 'expires_on' => '2026-10-20']);
    F::row('compliance_licences', ['id' => F::id('CLEEDS'), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'licence_type' => 'Premises', 'number' => 'P1', 'issued_on' => '2020-01-01', 'expires_on' => '2026-11-01']);
    F::row('product_recalls', ['id' => F::id('RCMINE'), 'company_id' => $id, 'reference' => 'RC-1', 'product_name' => 'Pies', 'status' => 'open', 'raised_at' => '2026-10-10 09:00:00', 'row_version' => 1]);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $shop = Branch::factory()->forCompany($this->other)->create(['name' => 'Other shop']);
    F::refusal($this->other->id, 'OTHER', $shop->id, F::ALI, '2026-10-14 10:00:00');
    F::row('incident_reports', ['id' => F::id('IOTHER'), 'company_id' => $this->other->id, 'branch_id' => $shop->id, 'occurred_at' => '2026-10-14 09:00:00', 'category' => 'Abuse']);
    F::row('product_recalls', ['id' => F::id('RCOTHER'), 'company_id' => $this->other->id, 'reference' => 'RC-X', 'product_name' => 'Theirs', 'status' => 'open', 'raised_at' => '2026-10-10 09:00:00', 'row_version' => 1]);
});

test('guests are sent to log in and staff get 403 on every Compliance page and write', function () {
    $pages = [...COMPLIANCE_PAGES, '/app/compliance/incidents/'.F::id('ILEEDS'), '/app/compliance/recalls/'.F::id('RCMINE')];

    foreach ($pages as $url) {
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(C::member($this->company, CompanyRole::Staff))->get($url)->assertForbidden();
        auth()->logout();
    }

    $staff = C::member($this->company, CompanyRole::Staff);
    $this->actingAs($staff)->post('/app/compliance/recalls', ['product_name' => 'X', 'reason' => 'Y'])->assertForbidden();
    $this->actingAs($staff)->put('/app/compliance/recalls/'.F::id('RCMINE'), ['product_name' => 'X', 'reason' => 'Y'])->assertForbidden();
});

test('owners, managers and accountants see every page; only owners and managers of every shop raise recalls', function () {
    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = C::member($this->company, $role);

        foreach (COMPLIANCE_PAGES as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        $this->actingAs($user)->get('/app/compliance/incidents/'.F::id('ILEEDS'))->assertOk();
        expect(C::props($this->actingAs($user)->get('/app/compliance/recalls'))['canManage'])->toBe($role !== CompanyRole::Accountant);
    }

    $accountant = C::member($this->company, CompanyRole::Accountant);
    $this->actingAs($accountant)->post('/app/compliance/recalls', ['product_name' => 'X', 'reason' => 'Y'])->assertForbidden();

    $oneShop = C::member($this->company, CompanyRole::Manager, TillFixtures::BRADFORD);
    expect(C::props($this->actingAs($oneShop)->get('/app/compliance/recalls'))['canManage'])->toBeFalse();
    $this->actingAs($oneShop)->post('/app/compliance/recalls', ['product_name' => 'X', 'reason' => 'Y'])->assertForbidden();
    $this->actingAs($oneShop)->put('/app/compliance/recalls/'.F::id('RCMINE'), ['product_name' => 'X', 'reason' => 'Y'])->assertForbidden();

    expect(CompanyRole::Staff->can('compliance.view'))->toBeFalse()
        ->and(CompanyRole::Accountant->can('compliance.manage'))->toBeFalse()
        ->and(CompanyRole::Manager->can('compliance.manage'))->toBeTrue();
});

test('a one-shop user sees only their shop, and another shop\'s incident is not found', function () {
    $manager = C::member($this->company, CompanyRole::Manager, TillFixtures::BRADFORD);

    $age = C::props($this->actingAs($manager)->get('/app/compliance/age-checks?shop=all'));
    expect(array_column($age['refusalLog']['data'], 'id'))->toBe([F::id('RBRAD')])
        ->and($age['filters'])->toMatchArray(['shop' => TillFixtures::BRADFORD, 'shopLocked' => true])
        ->and(array_column($age['options']['shops'], 'label'))->toBe(['Bradford']);

    expect(C::props($this->actingAs($manager)->get('/app/compliance/incidents?shop='.TillFixtures::LEEDS))['incidents']['data'])->toBe([])
        ->and(C::props($this->actingAs($manager)->get('/app/compliance/training?shop=all'))['records']['data'])->toBe([])
        ->and(C::props($this->actingAs($manager)->get('/app/compliance/licences?shop=all'))['licences']['data'])->toBe([])
        ->and(C::props($this->actingAs($manager)->get('/app/compliance'))['attention']['items'])->toHaveCount(1); // the company-wide recall only

    $this->actingAs($manager)->get('/app/compliance/incidents/'.F::id('ILEEDS'))->assertNotFound();
});

test('one business never sees or changes another business\'s compliance rows', function () {
    $owner = C::member($this->company, CompanyRole::Owner);

    expect(array_column(C::props($this->actingAs($owner)->get('/app/compliance/age-checks?shop=all'))['refusalLog']['data'], 'id'))
        ->toEqualCanonicalizing([F::id('RLEEDS'), F::id('RBRAD')])
        ->and(array_column(C::props($this->actingAs($owner)->get('/app/compliance/incidents?shop=all'))['incidents']['data'], 'id'))->toBe([F::id('ILEEDS')])
        ->and(array_column(C::props($this->actingAs($owner)->get('/app/compliance/recalls'))['recalls']['data'], 'id'))->toBe([F::id('RCMINE')]);

    $this->actingAs($owner)->get('/app/compliance/incidents/'.F::id('IOTHER'))->assertNotFound();
    $this->actingAs($owner)->get('/app/compliance/recalls/'.F::id('RCOTHER'))->assertNotFound();
    $this->actingAs($owner)->put('/app/compliance/recalls/'.F::id('RCOTHER'), ['product_name' => 'Mine now', 'reason' => 'x'])->assertNotFound();

    $this->assertDatabaseHas('product_recalls', ['id' => F::id('RCOTHER'), 'product_name' => 'Theirs', 'status' => 'open']);
});
