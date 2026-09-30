<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Accounts\AccountsFixtures as A;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.5: who may see Accounts and VAT (accounts.view: owner, manager, accountant), a one-shop user's own shop
 * only, and one business never seeing another's accounts, journals, expenses or VAT.
 */

const ACCOUNTS_PAGES = [
    '/app/accounts', '/app/accounts/journals', '/app/accounts/trial-balance', '/app/accounts/profit-and-loss', '/app/accounts/balance-sheet',
    '/app/accounts/expenses', '/app/accounts/vat', '/app/accounts/vat/print', '/app/accounts/fixed-assets',
];

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-23 18:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    A::book($this->company->id);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $shop = Branch::factory()->forCompany($this->other)->create(['name' => 'Other shop']);
    A::chart($this->other->id, $shop->id, 'O');
    A::entry($this->other->id, $shop->id, '01K5T0Q8C4000000000000JOTH', '2026-09-15', 'Sale', [['1000', '999.00', '0.00'], ['4000', '0.00', '999.00']]);
    DB::table('expenses')->insert(['id' => A::id('EXO', 1), 'company_id' => $this->other->id, 'branch_id' => $shop->id, 'expense_date' => '2026-09-15', 'payee_name' => 'Other payee', 'net' => '9.00', 'vat' => '0.00', 'gross' => '9.00']);
    DB::table('fixed_assets')->insert(['id' => A::id('FAO', 1), 'company_id' => $this->other->id, 'branch_id' => $shop->id, 'name' => 'Other fridge', 'purchase_date' => '2026-01-01', 'purchase_cost_amount' => '900.00', 'is_active' => true]);
    DB::table('fixed_assets')->insert(['id' => A::id('FAL', 1), 'company_id' => $this->company->id, 'branch_id' => TillFixtures::LEEDS, 'name' => 'Chiller', 'purchase_date' => '2026-02-01', 'purchase_cost_amount' => '1500.00', 'is_active' => true]);
});

test('guests are sent to log in and staff get 403 on every Accounts page', function () {
    foreach ([...ACCOUNTS_PAGES, '/app/accounts/vat/csv', '/app/accounts/journals/'.A::E_LEEDS] as $url) {
        $this->get($url)->assertRedirect(route('login'));
        $this->actingAs(C::member($this->company, CompanyRole::Staff))->get($url)->assertForbidden();
        auth()->logout();
    }
});

test('owners, managers and accountants may see every Accounts page; the ability is theirs only', function () {
    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = C::member($this->company, $role);

        foreach (ACCOUNTS_PAGES as $url) {
            $this->actingAs($user)->get($url)->assertOk();
        }

        $this->actingAs($user)->get('/app/accounts/journals/'.A::E_LEEDS)->assertOk();
        $this->actingAs($user)->get('/app/accounts/vat/csv')->assertOk();
        expect($role->can('accounts.view'))->toBeTrue();
    }

    expect(CompanyRole::Staff->can('accounts.view'))->toBeFalse();
});

test('a one-shop user sees only their shop, and another shop\'s journal is not found', function () {
    $manager = C::member($this->company, CompanyRole::Manager, TillFixtures::BRADFORD);
    $q = '?from=2026-09-01&to=2026-09-23&shop=all';

    $journals = C::props($this->actingAs($manager)->get('/app/accounts/journals'.$q));
    expect(array_column($journals['entries']['data'], 'id'))->toBe([A::E_BRAD])
        ->and($journals['filters'])->toMatchArray(['shop' => TillFixtures::BRADFORD, 'shopLocked' => true])
        ->and(array_column($journals['options']['shops'], 'label'))->toBe(['Bradford']);

    $tb = C::props($this->actingAs($manager)->get('/app/accounts/trial-balance?from=2026-09-01&to=2026-09-23&shop='.TillFixtures::LEEDS));
    expect(collect($tb['rows'])->pluck('debit', 'code')->filter()->all())->toBe(['1000' => '60.00'])
        ->and($tb['totals']['credit'])->toBe('60.00')
        ->and($tb['refundFix']['entries'])->toBe(0);

    $chart = C::props($this->actingAs($manager)->get('/app/accounts'.$q));
    expect(collect($chart['accounts'])->firstWhere('code', '4000')['shops'])->toBe(1);

    expect(C::props($this->actingAs($manager)->get('/app/accounts/fixed-assets'))['assets']['data'])->toBe([]);
    $this->actingAs($manager)->get('/app/accounts/journals/'.A::E_LEEDS)->assertNotFound();
    $this->actingAs($manager)->get('/app/accounts/journals/'.A::E_BRAD)->assertOk();
});

test('one business never sees another business\'s accounts, journals, expenses or assets', function () {
    $owner = C::member($this->company, CompanyRole::Owner);
    $q = '?from=2026-09-01&to=2026-09-23&shop=all';

    $journals = C::props($this->actingAs($owner)->get('/app/accounts/journals'.$q));
    expect(array_column($journals['entries']['data'], 'id'))->not->toContain('01K5T0Q8C4000000000000JOTH')
        ->and($journals['entries']['meta']['total'])->toBe(5);

    $chart = C::props($this->actingAs($owner)->get('/app/accounts'.$q));
    expect(collect($chart['accounts'])->firstWhere('code', '4000')['shops'])->toBe(2)
        ->and(collect($chart['accounts'])->firstWhere('code', '4000')['balance'])->toBe('175.00');

    expect(C::props($this->actingAs($owner)->get('/app/accounts/trial-balance'.$q))['totals']['debit'])->toBe('210.00')
        ->and(C::props($this->actingAs($owner)->get('/app/accounts/expenses'.$q))['expenses']['data'])->toBe([])
        ->and(array_column(C::props($this->actingAs($owner)->get('/app/accounts/fixed-assets'))['assets']['data'], 'name'))->toBe(['Chiller']);

    $this->actingAs($owner)->get('/app/accounts/journals/01K5T0Q8C4000000000000JOTH')->assertNotFound();
});
