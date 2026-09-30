<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.4: who may see Cash and Z (cash.view: owner, manager, accountant), a one-shop user's own shop only, and
 * one business never seeing another's rows.
 */

const CASH_PAGES = ['/app/cash', '/app/cash/z', '/app/cash/banking', '/app/cash/counts', '/app/cash/cards', '/app/cash/days', '/app/cash/alerts'];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    $id = $this->company->id;
    C::shift($id, TillFixtures::LEEDS, TillFixtures::TILL_1, '01K5T0Q8C40000000000LEED01', ['status' => 'closed', 'closed_at' => '2026-09-23 12:00:00'], ['10.00', '9.00', '-1.00']);
    C::shift($id, TillFixtures::BRADFORD, TillFixtures::BRADFORD_TILL, '01K5T0Q8C40000000000BRAD01', ['status' => 'closed', 'closed_at' => '2026-09-23 12:00:00'], ['20.00', '20.00', '0.00']);
    C::row('z_reports', ['id' => '01K5T0Q8C40000000000ZLEEDS', 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'shift_id' => '01K5T0Q8C40000000000LEED01', 'sequence_no' => 1, 'period_end' => '2026-09-23 12:00:00']);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $shop = Branch::factory()->forCompany($this->other)->create(['name' => 'Other shop']);
    $till = Register::factory()->forBranch($shop)->create();
    C::shift($this->other->id, $shop->id, $till->id, '01K5T0Q8C40000000000OTHR01', ['status' => 'closed', 'closed_at' => '2026-09-23 12:00:00'], ['99.00', '0.00', '-99.00']);
    C::row('z_reports', ['id' => '01K5T0Q8C40000000000ZOTHER', 'company_id' => $this->other->id, 'branch_id' => $shop->id, 'register_id' => $till->id, 'shift_id' => '01K5T0Q8C40000000000OTHR01', 'sequence_no' => 1, 'period_end' => '2026-09-23 12:00:00']);
});

test('guests are sent to log in and staff get 403 on every Cash and Z page', function () {
    foreach ([...CASH_PAGES, '/app/cash/shifts/01K5T0Q8C40000000000LEED01', '/app/cash/z/01K5T0Q8C40000000000ZLEEDS'] as $url) {
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(C::member($this->company, CompanyRole::Staff))->get($url)->assertForbidden();
        auth()->logout();
    }
});

test('owners, managers and accountants may see every Cash and Z page; the ability is theirs only', function () {
    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = C::member($this->company, $role);

        foreach (CASH_PAGES as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        $this->actingAs($user)->get('/app/cash/shifts/01K5T0Q8C40000000000LEED01')->assertOk();
        expect($role->can('cash.view'))->toBeTrue();
    }

    expect(CompanyRole::Staff->can('cash.view'))->toBeFalse();
});

test('a one-shop user sees only their shop, and another shop\'s shift or Z report is not found', function () {
    $manager = C::member($this->company, CompanyRole::Manager, TillFixtures::BRADFORD);

    $props = C::props($this->actingAs($manager)->get('/app/cash?shop=all'));
    expect(array_column($props['shifts']['data'], 'id'))->toBe(['01K5T0Q8C40000000000BRAD01'])
        ->and($props['filters'])->toMatchArray(['shop' => TillFixtures::BRADFORD, 'shopLocked' => true])
        ->and(array_column($props['options']['shops'], 'label'))->toBe(['Bradford']);

    $shop = C::props($this->actingAs($manager)->get('/app/cash?shop='.TillFixtures::LEEDS));
    expect(array_column($shop['shifts']['data'], 'id'))->toBe(['01K5T0Q8C40000000000BRAD01']);

    expect(C::props($this->actingAs($manager)->get('/app/cash/z?shop=all'))['reports']['data'])->toBe([])
        ->and(C::props($this->actingAs($manager)->get('/app/cash/alerts?shop=all'))['alerts']['data'])->toBe([]);

    $this->actingAs($manager)->get('/app/cash/shifts/01K5T0Q8C40000000000LEED01')->assertNotFound();
    $this->actingAs($manager)->get('/app/cash/z/01K5T0Q8C40000000000ZLEEDS')->assertNotFound();
    $this->actingAs($manager)->get('/app/cash/shifts/01K5T0Q8C40000000000BRAD01')->assertOk();
});

test('one business never sees another business\'s shifts, Z reports or alerts', function () {
    $owner = C::member($this->company, CompanyRole::Owner);

    $props = C::props($this->actingAs($owner)->get('/app/cash?shop=all'));
    expect(array_column($props['shifts']['data'], 'id'))->toEqualCanonicalizing(['01K5T0Q8C40000000000LEED01', '01K5T0Q8C40000000000BRAD01'])
        ->and($props['summary']['cashVariance'])->toBe('-1.00');

    expect(array_column(C::props($this->actingAs($owner)->get('/app/cash/z?shop=all'))['reports']['data'], 'id'))->toBe(['01K5T0Q8C40000000000ZLEEDS'])
        ->and(C::props($this->actingAs($owner)->get('/app/cash/alerts?shop=all&threshold=50'))['alerts']['data'])->toBe([]);

    $this->actingAs($owner)->get('/app/cash/shifts/01K5T0Q8C40000000000OTHR01')->assertNotFound();
    $this->actingAs($owner)->get('/app/cash/z/01K5T0Q8C40000000000ZOTHER')->assertNotFound();

    $theirs = C::props($this->actingAs(C::member($this->other, CompanyRole::Owner))->get('/app/cash?shop=all'));
    expect(array_column($theirs['shifts']['data'], 'id'))->toBe(['01K5T0Q8C40000000000OTHR01']);
});
