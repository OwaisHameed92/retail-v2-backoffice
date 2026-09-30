<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Stock\StockFixtures as S;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Module 5.1: stock on hand (per shop and every shop), its figures against the stored rows, filters, who may see it,
 * the one-shop rule and tenant isolation. "Now" is Friday 25 Sept 2026, 12:00 London. Low-stock point: the default 5.
 *
 * Leeds: Apples 12 @ £1.25, Bread 3 (low) @ £0.50, Cola 0 (out) @ £0.80, Dates −2 (negative) @ £2.00,
 * Eggs 40 not stock-tracked (left out). Bradford: Apples 4 (low), Bread 20.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/London'));
    [$this->company, $this->leeds, $this->bradford] = T::tenant();
    $c = $this->company->id;
    $this->ids = ['apples' => S::id('PRD', 1), 'bread' => S::id('PRD', 2), 'cola' => S::id('PRD', 3), 'dates' => S::id('PRD', 4), 'eggs' => S::id('PRD', 5)];
    $this->dept = S::id('DEPT', 1);
    $this->supplier = S::id('SUPP', 1);
    DB::table('departments')->insert(['id' => $this->dept, 'company_id' => $c, 'name' => 'Grocery']);
    DB::table('suppliers')->insert(['id' => $this->supplier, 'company_id' => $c, 'name' => 'Booker']);

    S::product($c, $this->ids['apples'], 'Apples', '1.25', ['department_id' => $this->dept]);
    S::product($c, $this->ids['bread'], 'Bread', '0.50');
    S::product($c, $this->ids['cola'], 'Cola', '0.80');
    S::product($c, $this->ids['dates'], 'Dates', '2.00');
    S::product($c, $this->ids['eggs'], 'Eggs', '0.20', ['track_stock' => false]);
    DB::table('product_suppliers')->insert(['id' => S::id('PSUP', 1), 'company_id' => $c, 'product_id' => $this->ids['bread'], 'supplier_id' => $this->supplier]);
    DB::table('product_barcodes')->insert(['id' => S::id('BAR', 1), 'company_id' => $c, 'product_id' => $this->ids['cola'], 'barcode' => '5000112545678']);

    S::line($c, T::LEEDS, $this->ids['apples'], '12');
    S::line($c, T::LEEDS, $this->ids['bread'], '3');
    S::line($c, T::LEEDS, $this->ids['cola'], '0');
    S::line($c, T::LEEDS, $this->ids['dates'], '-2');
    S::line($c, T::LEEDS, $this->ids['eggs'], '40');
    S::line($c, T::BRADFORD, $this->ids['apples'], '4');
    S::line($c, T::BRADFORD, $this->ids['bread'], '20');

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    S::product($this->other->id, S::id('OTHER', 1), 'Other apples', '9.99');
    S::line($this->other->id, $this->otherShop->id, S::id('OTHER', 1), '100');

    $this->owner = S::member($this->company, CompanyRole::Owner);
    $this->props = fn (User $user, string $url) => $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
    $this->names = fn (User $user, string $query) => collect(($this->props)($user, '/app/stock'.$query)['stock']['data'])->pluck('name')->all();
});

test('guests go to the login page; owner, manager, accountant and staff may look; without stock.view it is 403', function () {
    $urls = ['/app/stock', '/app/stock/movements', '/app/stock/valuation', '/app/stock/expiry', '/app/stock/takes',
        '/app/stock/takes/'.S::id('TAKEA', 1), '/app/stock/products/'.$this->ids['apples']];

    foreach ($urls as $url) {
        $this->get($url)->assertRedirect('/login');
    }

    $this->put('/app/stock/products/'.$this->ids['apples'].'/levels')->assertRedirect('/login');

    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant, CompanyRole::Staff] as $role) {
        $this->actingAs(S::member($this->company, $role))->get('/app/stock')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('app/stock/index'));
    }

    $this->partialMock(CurrentCompany::class, function ($mock) {
        $mock->shouldReceive('can')->with('stock.view')->andReturn(false);
    });
    $user = S::member($this->company, CompanyRole::Manager);

    foreach ($urls as $url) {
        $this->actingAs($user)->get($url)->assertForbidden();
    }
});

test('one shop: a row per stock line with figures equal to the stored rows, worst first', function () {
    $props = ($this->props)($this->owner, '/app/stock?shop='.T::LEEDS);

    expect($props['summary'])->toBe([
        'lines' => 4, 'products' => 4, 'units' => '13.0000', 'value' => '12.50', 'costed' => 4, 'low' => 3, 'out' => 2, 'negative' => 1,
    ])
        ->and(collect($props['stock']['data'])->pluck('name')->all())->toBe(['Dates', 'Cola', 'Bread', 'Apples'])
        ->and(collect($props['stock']['data'])->pluck('status')->all())->toBe(['negative', 'out', 'low', 'ok'])
        ->and($props['stock']['meta'])->toBe(['page' => 1, 'perPage' => 50, 'total' => 4])
        ->and($props['filters']['shop'])->toBe(T::LEEDS);

    $apples = $props['stock']['data'][3];
    expect($apples)->toMatchArray(['onHand' => '12.0000', 'available' => '12.0000', 'lowAt' => '5.0000', 'cost' => '1.2500', 'value' => '15.00', 'department' => 'Grocery'])
        ->and($props['stock']['data'][0]['value'])->toBe('-4.00');
});

test('status filters: low (out included), out (none or less) and negative', function () {
    $n = fn (string $q) => ($this->names)($this->owner, '?shop='.T::LEEDS.$q);

    expect($n('&status=low'))->toBe(['Dates', 'Cola', 'Bread'])
        ->and($n('&status=out'))->toBe(['Dates', 'Cola'])
        ->and($n('&status=negative'))->toBe(['Dates'])
        ->and($n('&status=bogus'))->toHaveCount(4);
});

test('a shop line or product minimum overrides the low-stock point, then the shop setting', function () {
    DB::table('products')->where('id', $this->ids['apples'])->update(['min_stock_qty' => '12']);
    expect(($this->names)($this->owner, '?shop='.T::LEEDS.'&status=low'))->toContain('Apples');

    DB::table('branch_products')->where('product_id', $this->ids['apples'])->where('branch_id', T::LEEDS)->update(['reorder_point' => '2']);
    expect(($this->names)($this->owner, '?shop='.T::LEEDS.'&status=low'))->not->toContain('Apples');

    DB::table('till_settings')->insert(['id' => S::id('SET', 1), 'company_id' => $this->company->id, 'scope' => 'branch', 'scope_id' => T::BRADFORD,
        'setting_key' => 'stock.low_stock_threshold', 'value' => '25']);
    expect(($this->names)($this->owner, '?shop='.T::BRADFORD.'&status=low'))->toBe(['Apples', 'Bread']);
});

test('every shop: a row per product with the shops added up and a product matching when any shop does', function () {
    $props = ($this->props)($this->owner, '/app/stock?shop=all');
    $rows = collect($props['stock']['data'])->keyBy('name');

    expect($props['summary'])->toMatchArray(['lines' => 6, 'products' => 4, 'units' => '37.0000', 'value' => '27.50', 'low' => 4])
        ->and($rows->keys()->all())->toBe(['Dates', 'Cola', 'Apples', 'Bread'])
        ->and($rows['Apples'])->toMatchArray(['onHand' => '16.0000', 'shops' => 2, 'lowShops' => 1, 'outShops' => 0, 'value' => '20.00', 'status' => 'low'])
        ->and($rows['Bread'])->toMatchArray(['onHand' => '23.0000', 'shops' => 2, 'lowShops' => 1, 'status' => 'low'])
        ->and(($this->names)($this->owner, '?shop=all&status=out'))->toBe(['Dates', 'Cola'])
        ->and(($this->names)($this->owner, '?shop=all&status=low'))->toHaveCount(4);
});

test('search by name, SKU prefix or exact barcode; department and supplier filters', function () {
    $n = fn (string $q) => ($this->names)($this->owner, '?shop=all'.$q);

    expect($n('&search=appl'))->toBe(['Apples'])
        ->and($n('&search=SKU-0002'))->toBe(['Bread'])
        ->and($n('&search=5000112545678'))->toBe(['Cola'])
        ->and($n('&search=50001'))->toBe([])
        ->and($n('&department='.$this->dept))->toBe(['Apples'])
        ->and($n('&supplier='.$this->supplier))->toBe(['Bread']);
});

test('paging: a page of lines and the total', function () {
    $props = ($this->props)($this->owner, '/app/stock?shop='.T::LEEDS.'&perPage=25&page=2');

    expect($props['stock']['data'])->toBe([])->and($props['stock']['meta']['total'])->toBe(4)->and($props['stock']['meta']['page'])->toBe(2);
});

test('a one-shop user sees only their shop, whatever the query asks', function () {
    $manager = S::member($this->company, CompanyRole::Manager, T::BRADFORD);
    $props = ($this->props)($manager, '/app/stock?shop=all');

    expect($props['filters'])->toMatchArray(['shop' => T::BRADFORD, 'shopLocked' => true])
        ->and($props['summary']['lines'])->toBe(2)
        ->and(collect($props['stock']['data'])->pluck('name')->all())->toBe(['Apples', 'Bread'])
        ->and($props['options']['shops'])->toBe([['value' => T::BRADFORD, 'label' => 'Bradford']])
        ->and(collect(($this->props)($manager, '/app/stock?shop='.T::LEEDS)['stock']['data'])->pluck('shopId')->unique()->all())->toBe([T::BRADFORD]);

    $product = ($this->props)($manager, '/app/stock/products/'.$this->ids['apples']);
    expect(collect($product['lines'])->pluck('shop')->all())->toBe(['Bradford']);
});

test('another business: its stock never shows, and its products are not found', function () {
    $props = ($this->props)($this->owner, '/app/stock?shop=all&search=Other');
    expect($props['stock']['data'])->toBe([])
        ->and(($this->props)($this->owner, '/app/stock?shop=all')['summary']['lines'])->toBe(6)
        ->and(($this->props)($this->owner, '/app/stock?shop='.$this->otherShop->id)['stock']['data'])->toBe([]);

    $this->actingAs($this->owner)->get('/app/stock/products/'.S::id('OTHER', 1))->assertNotFound();
    $this->actingAs($this->owner)->put('/app/stock/products/'.S::id('OTHER', 1).'/levels', ['min_stock_qty' => '3'])->assertNotFound();
    expect(DB::table('products')->where('id', S::id('OTHER', 1))->value('min_stock_qty'))->toBeNull();
});

test('the product page: each shop line, totals and the product levels', function () {
    $props = ($this->props)($this->owner, '/app/stock/products/'.$this->ids['apples'].'?shop=all');

    expect(collect($props['lines'])->pluck('shop')->all())->toBe(['Bradford', 'Leeds'])
        ->and($props['totals'])->toBe(['onHand' => '16.0000', 'fifoValue' => '20.00', 'costValue' => '20.00'])
        ->and($props['product'])->toMatchArray(['name' => 'Apples', 'costPrice' => '1.2500', 'minStockQty' => null, 'trackStock' => true])
        ->and($props['canManage'])->toBeTrue()
        ->and(($this->props)(S::member($this->company, CompanyRole::Staff), '/app/stock/products/'.$this->ids['apples'])['canManage'])->toBeFalse();
});
