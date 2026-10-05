<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Compliance\ComplianceFixtures as F;
use Tests\Feature\TillData\TillFixtures;

/* Module 5.7: age check figures (checks, refusals, rate, breakdowns) and the exceptions report per staff member. */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-15 12:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    $id = $this->company->id;
    F::staff($id);
    F::beer($id);
    $this->owner = C::member($this->company, CompanyRole::Owner);
});

test('age checks count sales with an age-restricted line, refusals, the refusal rate and each breakdown', function () {
    $id = $this->company->id;
    // Leeds: Ali passes 3 checks (one sale has two restricted lines: still one check), Bea 1; Ali refuses once.
    F::sale($id, 'A1', TillFixtures::LEEDS, F::ALI, '2026-10-14 10:00:00');
    DB::table('sale_lines')->insert(['id' => F::id('LA1B'), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'sale_id' => F::id('SA1'), 'product_id' => F::id('PBEER'), 'qty' => '2.0000', 'is_age_restricted' => true]);
    F::sale($id, 'A2', TillFixtures::LEEDS, F::ALI, '2026-10-14 11:00:00');
    F::sale($id, 'A3', TillFixtures::LEEDS, F::ALI, '2026-10-13 11:00:00');
    F::sale($id, 'B1', TillFixtures::LEEDS, F::BEA, '2026-10-13 11:00:00');
    F::refusal($id, 'A1', TillFixtures::LEEDS, F::ALI, '2026-10-14 12:00:00');
    // Bradford: 1 refusal (a lottery rule), no checks.
    F::refusal($id, 'B1', TillFixtures::BRADFORD, F::BEA, '2026-10-14 12:00:00', ['age_rule' => 'lottery18', 'product_id' => F::id('PLOTTO'), 'product_name' => 'Lotto']);
    // Not counted: a line not restricted, a voided sale, a refund, a quote, and a sale outside the dates.
    F::sale($id, 'X1', TillFixtures::LEEDS, F::ALI, '2026-10-14 10:00:00', ['is_age_restricted' => false]);
    F::sale($id, 'X2', TillFixtures::LEEDS, F::ALI, '2026-10-14 10:00:00');
    DB::table('sales')->where('id', F::id('SX2'))->update(['status' => 'voided']);
    F::sale($id, 'X3', TillFixtures::LEEDS, F::ALI, '2026-10-14 10:00:00');
    DB::table('sales')->where('id', F::id('SX3'))->update(['type' => 'refund']);
    F::sale($id, 'X4', TillFixtures::LEEDS, F::ALI, '2026-08-01 10:00:00');

    $p = C::props($this->actingAs($this->owner)->get('/app/compliance/age-checks?shop=all&from=2026-10-01&to=2026-10-15'));

    expect($p['summary'])->toBe(['checks' => 4, 'refusals' => 2, 'rate' => '33.3'])
        ->and(collect($p['byShop'])->keyBy('label')->map(fn ($r) => [$r['checks'], $r['refusals'], $r['rate']])->all())
        ->toBe(['Leeds' => [4, 1, '20.0'], 'Bradford' => [0, 1, '100.0']])
        ->and(collect($p['byStaff'])->keyBy('label')->map(fn ($r) => [$r['checks'], $r['refusals']])->all())
        ->toBe(['Ali Khan' => [3, 1], 'Bea Jones' => [1, 1]])
        ->and(collect($p['byRule'])->keyBy('label')->map(fn ($r) => [$r['checks'], $r['refusals']])->all())
        ->toBe(['18+' => [4, 1], 'Lottery 18+' => [0, 1]])
        ->and(collect($p['byProduct'])->map(fn ($r) => [$r['label'], $r['checks'], $r['refusals']])->all())
        ->toEqualCanonicalizing([['Beer', 4, 1], ['Lotto', 0, 1]])
        ->and($p['refusalLog']['data'][0])->toMatchArray(['staff' => 'Bea Jones', 'shop' => 'Bradford', 'rule' => 'Lottery 18+', 'product' => 'Lotto']);

    // Narrowed to one staff member and one rule.
    expect(C::props($this->actingAs($this->owner)->get('/app/compliance/age-checks?shop=all&from=2026-10-01&to=2026-10-15&staff='.F::ALI))['summary'])
        ->toBe(['checks' => 3, 'refusals' => 1, 'rate' => '25.0'])
        ->and(C::props($this->actingAs($this->owner)->get('/app/compliance/age-checks?shop=all&from=2026-10-01&to=2026-10-15&rule=lottery18'))['summary'])
        ->toBe(['checks' => 0, 'refusals' => 1, 'rate' => '100.0']);
});

test('the exceptions report counts no sales, voided lines and other exceptions per staff member', function () {
    $id = $this->company->id;
    $ex = fn (string $tag, string $user, string $type, string $amount, string $at = '2026-10-14 10:00:00') => F::row('exception_logs', [
        'id' => F::id('E'.$tag), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'user_id' => $user, 'type' => $type, 'amount' => $amount, 'at' => $at,
    ]);
    $ex('1', F::ALI, 'NoSale', '0.00');
    $ex('2', F::ALI, 'NoSale', '0.00');
    $ex('3', F::ALI, 'PriceOverride', '1.50');
    $ex('4', F::BEA, 'NoSale', '0.00');
    $ex('5', F::BEA, 'NoSale', '0.00', '2026-07-01 10:00:00'); // outside the dates
    F::row('till_audit_logs', ['id' => F::id('V1'), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'user_id' => F::BEA, 'entity_name' => 'Sale', 'entity_id' => 'CART1', 'action' => 'LineVoided', 'after_json' => '{"product":"Beer","quantity":2,"value":3.5}', 'reason' => 'Changed mind', 'at' => '2026-10-14 11:00:00']);
    F::row('till_audit_logs', ['id' => F::id('V2'), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'user_id' => F::BEA, 'entity_name' => 'Drawer', 'action' => 'NoSale', 'at' => '2026-10-14 11:00:00']);

    $p = C::props($this->actingAs($this->owner)->get('/app/compliance/exceptions?shop=all&from=2026-10-01&to=2026-10-15'));

    expect(collect($p['staff'])->map(fn ($r) => [$r['staff'], $r['noSales'], $r['voidedLines'], $r['other'], $r['total'], $r['amount']])->all())
        ->toBe([['Ali Khan', 2, 0, 1, 3, '1.50'], ['Bea Jones', 1, 1, 0, 2, '0.00']])
        ->and($p['summary'])->toBe(['noSales' => 3, 'voidedLines' => 1, 'other' => 1, 'amount' => '1.50'])
        ->and(collect($p['types'])->pluck('count', 'label')->all())->toBe(['No sale' => 3, 'Price override' => 1])
        ->and($p['log']['kind'])->toBe('exceptions')
        ->and($p['log']['meta']['total'])->toBe(4);

    $voids = C::props($this->actingAs($this->owner)->get('/app/compliance/exceptions?shop=all&from=2026-10-01&to=2026-10-15&type=LineVoided'))['log'];
    expect($voids['kind'])->toBe('voids')
        ->and($voids['data'][0])->toMatchArray(['staff' => 'Bea Jones', 'amount' => '3.50', 'detail' => '2 × Beer · Changed mind']);
});

test('till 0.1.28 voids: an ExceptionLog LineVoided is not counted twice, the new types have words, PascalCase void details are read', function () {
    $id = $this->company->id;
    $ex = fn (string $tag, string $type, string $amount) => F::row('exception_logs', [
        'id' => F::id('E'.$tag), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'user_id' => F::BEA, 'type' => $type, 'amount' => $amount, 'at' => '2026-10-14 10:00:00',
    ]);
    $ex('1', 'LineVoided', '1.20');
    $ex('2', 'CartCleared', '8.40');
    $ex('3', 'HeldSaleDiscarded', '3.00');
    F::row('till_audit_logs', ['id' => F::id('V1'), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, 'register_id' => TillFixtures::TILL_1, 'user_id' => F::BEA, 'entity_name' => 'Sale', 'entity_id' => 'CART1', 'action' => 'LineVoided', 'after_json' => '{"ProductId":"P1","ProductName":"Crisps","Quantity":1,"Value":1.2,"Reason":"Not asked","ReasonId":"","ApprovedBy":""}', 'reason' => 'Not asked', 'at' => '2026-10-14 10:00:00']);

    $p = C::props($this->actingAs($this->owner)->get('/app/compliance/exceptions?shop=all&from=2026-10-01&to=2026-10-15'));

    expect($p['summary'])->toBe(['noSales' => 0, 'voidedLines' => 1, 'other' => 2, 'amount' => '11.40'])
        ->and(collect($p['types'])->pluck('label')->sort()->values()->all())->toBe(['Held sale thrown away', 'Sale cleared before payment']);

    $voids = C::props($this->actingAs($this->owner)->get('/app/compliance/exceptions?shop=all&from=2026-10-01&to=2026-10-15&type=LineVoided'))['log'];
    expect($voids['data'][0])->toMatchArray(['amount' => '1.20', 'detail' => '1 × Crisps · Not asked']);
});
