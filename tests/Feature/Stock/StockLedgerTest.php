<?php

use App\Domain\Stock\Actions\SetProductStockLevels;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Queries\MovementSearch;
use App\Domain\Stock\Support\FifoValuation;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Stock\StockFixtures as S;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Module 5.1: movements (keyset paging, kinds, totals), stock takes (read only, variances as stored), FIFO valuation,
 * dates and wastage, and the one write (a product's stock levels). "Now" is Friday 25 Sept 2026, 12:00 London.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/London'));
    [$this->company, $this->leeds, $this->bradford] = T::tenant();
    $c = $this->company->id;
    $this->apples = S::id('PRD', 1);
    $this->bread = S::id('PRD', 2);
    S::product($c, $this->apples, 'Apples', '1.25');
    S::product($c, $this->bread, 'Bread', '0.50');
    S::line($c, T::LEEDS, $this->apples, '12');
    S::line($c, T::BRADFORD, $this->apples, '5');
    S::line($c, T::LEEDS, $this->bread, '4');

    // Seven Leeds movements a day apart (newest 24 Sept), one in Bradford.
    $n = 0;

    foreach (['sale' => '-2', 'refund' => '1', 'received' => '24', 'wastage' => '-1', 'expiry' => '-3', 'transfer' => '-5', 'stockTake' => '2'] as $type => $qty) {
        $n++;
        S::movement($c, T::LEEDS, S::id('MOV', $n), $this->apples, $type, $qty, CarbonImmutable::parse('2026-09-24 09:00')->subDays($n - 1)->format('Y-m-d H:i:s'));
    }
    S::movement($c, T::BRADFORD, S::id('MOV', 50), $this->apples, 'damaged', '-1', '2026-09-24 10:00:00', ['unit_cost' => '1.2000']);

    $this->other = Company::factory()->create(['name' => 'Other Stores']);
    $this->otherShop = Branch::factory()->forCompany($this->other)->create(['code' => 'OTH', 'name' => 'Other shop']);
    S::movement($this->other->id, $this->otherShop->id, S::id('OTHERMOV', 1), S::id('OTHER', 1), 'sale', '-9', '2026-09-24 11:00:00');
    S::take($this->other->id, $this->otherShop->id, S::id('TAKEZ', 1), 'approved', '2026-09-20 08:00:00', [[S::id('OTHER', 1), 'Other', '5', '1', '1.00', '-4', '-4.00']]);

    $this->owner = S::member($this->company, CompanyRole::Owner);
    $this->props = fn (User $user, string $url) => $this->actingAs($user)->get($url)->assertOk()->viewData('page')['props'];
});

test('movements: newest first with names and value, kinds and totals equal to the stored rows', function () {
    $props = ($this->props)($this->owner, '/app/stock/movements?shop=all');
    $rows = collect($props['movements']['data']);

    expect($rows->pluck('id')->all())->toBe([S::id('MOV', 50), S::id('MOV', 1), S::id('MOV', 2), S::id('MOV', 3), S::id('MOV', 4), S::id('MOV', 5), S::id('MOV', 6), S::id('MOV', 7)])
        ->and($rows[0])->toMatchArray(['product' => 'Apples', 'shop' => 'Bradford', 'typeLabel' => 'Damaged', 'qty' => '-1.0000', 'value' => '-1.20'])
        ->and($rows[3])->toMatchArray(['typeLabel' => 'Goods in', 'group' => 'goodsIn', 'qty' => '24.0000', 'value' => '12.00'])
        ->and(collect($props['summary'])->keyBy('group')['wastage'])->toMatchArray(['count' => 3, 'qty' => '-5.0000', 'value' => '-3.20'])
        ->and(collect($props['summary'])->keyBy('group')['sales'])->toMatchArray(['count' => 2, 'qty' => '-1.0000', 'value' => '-0.50'])
        ->and($rows->pluck('id'))->not->toContain(S::id('OTHERMOV', 1));

    $ids = fn (string $q) => collect(($this->props)($this->owner, '/app/stock/movements'.$q)['movements']['data'])->pluck('id')->all();
    expect($ids('?shop='.T::BRADFORD))->toBe([S::id('MOV', 50)])
        ->and($ids('?shop=all&type=wastage'))->toBe([S::id('MOV', 50), S::id('MOV', 4), S::id('MOV', 5)])
        ->and($ids('?shop=all&product='.$this->bread))->toBe([])
        ->and($ids('?shop=all&from=2026-09-22&to=2026-09-23'))->toBe([S::id('MOV', 2), S::id('MOV', 3)])
        ->and($ids('?shop=all&type=bogus&from=junk'))->toHaveCount(8);
});

test('movements: keyset paging walks every row once, older then newer', function () {
    $query = app(CurrentCompany::class)->runAs($this->company, fn () => MovementSearch::query(new StockFilters(from: '2026-09-01', to: '2026-09-25')));
    $seen = [];
    $after = null;
    $pages = [];

    do {
        $page = app(CurrentCompany::class)->runAs($this->company, fn () => MovementSearch::page($query, $after, null, 3));
        $seen = [...$seen, ...$page['rows']->pluck('id')->all()];
        $pages[] = $page;
        $after = $page['older'];
    } while ($after !== null);

    expect($seen)->toHaveCount(8)->and(array_unique($seen))->toHaveCount(8)->and($pages)->toHaveCount(3)
        ->and($pages[0]['newer'])->toBeNull();

    $back = app(CurrentCompany::class)->runAs($this->company, fn () => MovementSearch::page($query, null, $pages[2]['newer'], 3));
    expect($back['rows']->pluck('id')->all())->toBe($pages[1]['rows']->pluck('id')->all());

    $http = ($this->props)($this->owner, '/app/stock/movements?shop=all&perPage=25&after='.$pages[0]['older']);
    expect(collect($http['movements']['data'])->pluck('id')->all())->toBe(array_slice($seen, 3));
});

test('a one-shop user sees only their shop movements, stock takes and valuation', function () {
    S::take($this->company->id, T::LEEDS, S::id('TAKEA', 1), 'approved', '2026-09-20 08:00:00', []);
    S::take($this->company->id, T::BRADFORD, S::id('TAKEB', 1), 'counting', '2026-09-21 08:00:00', []);
    $user = S::member($this->company, CompanyRole::Manager, T::BRADFORD);

    expect(collect(($this->props)($user, '/app/stock/movements?shop=all')['movements']['data'])->pluck('id')->all())->toBe([S::id('MOV', 50)])
        ->and(collect(($this->props)($user, '/app/stock/takes?shop='.T::LEEDS)['takes']['data'])->pluck('id')->all())->toBe([S::id('TAKEB', 1)])
        ->and(($this->props)($user, '/app/stock/valuation?shop=all')['totals']['lines'])->toBe(1);

    $this->actingAs($user)->get('/app/stock/takes/'.S::id('TAKEA', 1))->assertNotFound();
    $this->actingAs($user)->get('/app/stock/takes/'.S::id('TAKEB', 1))->assertOk();
});

test('stock takes: listed newest first with the stored variances added up; the detail and its variance view', function () {
    S::take($this->company->id, T::LEEDS, S::id('TAKEA', 1), 'approved', '2026-09-20 08:00:00', [
        [$this->apples, 'Apples', '12', '10', '1.2500', '-2', '-2.5000'],
        [$this->bread, 'Bread', '4', '5', '0.5000', '1', '0.5000'],
        [S::id('PRD', 9), 'Milk', '6', null, '0.9000', '0', '0'],
    ]);
    S::take($this->company->id, T::LEEDS, S::id('TAKEB', 1), 'counting', '2026-09-22 08:00:00', []);

    $list = ($this->props)($this->owner, '/app/stock/takes?shop=all');
    expect(collect($list['takes']['data'])->pluck('id')->all())->toBe([S::id('TAKEB', 1), S::id('TAKEA', 1)])
        ->and($list['takes']['data'][1])->toMatchArray(['shop' => 'Leeds', 'status' => 'approved', 'lines' => 3, 'counted' => 2, 'varianceQty' => '-1.0000', 'varianceCost' => '-2.00'])
        ->and(collect(($this->props)($this->owner, '/app/stock/takes?shop=all&status=counting')['takes']['data'])->pluck('id')->all())->toBe([S::id('TAKEB', 1)]);

    $detail = ($this->props)($this->owner, '/app/stock/takes/'.S::id('TAKEA', 1));
    expect($detail['take'])->toMatchArray(['lines' => 3, 'counted' => 2, 'varianceCost' => '-2.00', 'gains' => '0.50', 'losses' => '-2.50', 'snapshotValue' => '22.40'])
        ->and(collect($detail['lines']['data'])->pluck('name')->all())->toBe(['Apples', 'Bread', 'Milk'])
        ->and($detail['lines']['data'][2]['counted'])->toBeNull()
        ->and(collect(($this->props)($this->owner, '/app/stock/takes/'.S::id('TAKEA', 1).'?view=variances')['lines']['data'])->pluck('name')->all())->toBe(['Apples', 'Bread']);

    $this->actingAs($this->owner)->get('/app/stock/takes/'.S::id('TAKEZ', 1))->assertNotFound();
});

test('FIFO: newest layers cover the stock on hand, the rest at cost price; nothing on hand is worth nothing', function () {
    $layers = [
        ['qty' => '10', 'cost' => '1.00', 'at' => '2026-09-01 08:00:00', 'id' => 'A'],
        ['qty' => '5', 'cost' => '1.20', 'at' => '2026-09-10 08:00:00', 'id' => 'B'],
        ['qty' => '0', 'cost' => '9.99', 'at' => '2026-09-20 08:00:00', 'id' => 'C'],
    ];

    expect(FifoValuation::of('8', $layers, '1.50'))->toBe(['value' => '9.00', 'fifoQty' => '8.0000', 'costQty' => '0.0000', 'unvaluedQty' => '0.0000', 'basis' => 'fifo'])
        ->and(FifoValuation::of('15', $layers, '1.50')['value'])->toBe('16.00')
        ->and(FifoValuation::of('18', $layers, '1.50'))->toMatchArray(['value' => '20.50', 'costQty' => '3.0000', 'basis' => 'mixed'])
        ->and(FifoValuation::of('18', $layers, '0'))->toMatchArray(['value' => '16.00', 'unvaluedQty' => '3.0000'])
        ->and(FifoValuation::of('4', [], '1.25'))->toMatchArray(['value' => '5.00', 'basis' => 'cost'])
        ->and(FifoValuation::of('-2', $layers, '1.25'))->toMatchArray(['value' => '0.00', 'basis' => 'none'])
        ->and(FifoValuation::of('0.3333', [['qty' => '1', 'cost' => '0.3333']], '0'))->toMatchArray(['value' => '0.11']);
});

test('valuation page: FIFO from the till cost layers against cost price, by shop and department', function () {
    $c = $this->company->id;
    S::fifo($c, T::LEEDS, S::id('FIFO', 1), $this->apples, '10', '1.00', '2026-09-01 08:00:00');
    S::fifo($c, T::LEEDS, S::id('FIFO', 2), $this->apples, '6', '1.10', '2026-09-15 08:00:00');
    S::fifo($c, T::BRADFORD, S::id('FIFO', 3), $this->apples, '2', '1.40', '2026-09-15 08:00:00');
    S::fifo($this->other->id, $this->otherShop->id, S::id('FIFO', 9), $this->apples, '99', '9.00', '2026-09-15 08:00:00');

    $props = ($this->props)($this->owner, '/app/stock/valuation?shop=all');

    // Leeds apples 12: 6 @ 1.10 + 6 @ 1.00 = 12.60; Bradford apples 5: 2 @ 1.40 + 3 @ 1.25 = 6.55; Leeds bread 4 @ 0.50 = 2.00.
    expect($props['totals'])->toMatchArray(['lines' => 3, 'fifoValue' => '21.15', 'costValue' => '23.25', 'difference' => '-2.10', 'layerRows' => 3])
        ->and($props['totals']['basis'])->toBe(['fifo' => 1, 'mixed' => 1, 'cost' => 1, 'none' => 0])
        ->and(collect($props['byShop'])->keyBy('name')['Leeds'])->toMatchArray(['fifo' => '14.60', 'cost' => '17.00', 'lines' => 2])
        ->and($props['byDepartment'][0])->toMatchArray(['name' => 'No department', 'fifo' => '21.15'])
        ->and($props['top'][0])->toMatchArray(['name' => 'Apples', 'shop' => 'Leeds', 'fifoValue' => '12.60', 'basis' => 'fifo']);
});

test('dates and wastage: batches out of date or near it, buckets, date checks and write-offs', function () {
    $c = $this->company->id;
    S::batch($c, T::LEEDS, S::id('LAYER', 1), $this->apples, '3', '1.00', '2026-09-23', 'OLD');
    S::batch($c, T::LEEDS, S::id('LAYER', 2), $this->apples, '4', '1.10', '2026-09-28', 'SOON');
    S::batch($c, T::LEEDS, S::id('LAYER', 3), $this->bread, '2', '0.50', '2026-10-20', 'LATER');
    S::batch($c, T::LEEDS, S::id('LAYER', 4), $this->bread, '0', '0.50', '2026-09-20', 'GONE');
    S::batch($c, T::LEEDS, S::id('LAYER', 5), $this->bread, '9', '0.50', null, 'NODATE');
    DB::table('date_checks')->insert(['id' => S::id('CHECK', 1), 'company_id' => $c, 'branch_id' => T::LEEDS, 'stock_layer_id' => S::id('LAYER', 1),
        'product_id' => $this->apples, 'checked_by_user_id' => '', 'checked_at' => '2026-09-24 07:00:00', 'action' => 'reduced', 'markdown_percent' => '50', 'note' => '']);

    $props = ($this->props)($this->owner, '/app/stock/expiry?shop=all');
    expect(collect($props['batches']['data'])->pluck('batch')->all())->toBe(['OLD', 'SOON'])
        ->and($props['batches']['data'][0])->toMatchArray(['expired' => true, 'daysLeft' => -2, 'value' => '3.00'])
        ->and($props['batches']['data'][1])->toMatchArray(['expired' => false, 'daysLeft' => 3, 'qty' => '4.0000'])
        ->and($props['buckets'])->toBe([
            'expired' => ['count' => 1, 'qty' => '3.0000', 'value' => '3.00'],
            'week' => ['count' => 1, 'qty' => '4.0000', 'value' => '4.40'],
            'month' => ['count' => 2, 'qty' => '6.0000', 'value' => '5.40'],
        ])
        ->and($props['checks'][0])->toMatchArray(['name' => 'Apples', 'action' => 'reduced', 'markdownPercent' => '50.00'])
        ->and($props['wastage'])->toMatchArray(['qty' => '5.0000', 'value' => '3.20'])
        ->and(collect(($this->props)($this->owner, '/app/stock/expiry?shop=all&within=0')['batches']['data'])->pluck('batch')->all())->toBe(['OLD'])
        ->and(collect(($this->props)($this->owner, '/app/stock/expiry?shop=all&within=30')['batches']['data'])->pluck('batch')->all())->toBe(['OLD', 'SOON', 'LATER']);
});

test('stock levels: owner and manager set the product (hub-owned) levels; accountant and staff get 403', function () {
    $url = '/app/stock/products/'.$this->apples.'/levels';

    foreach ([CompanyRole::Accountant, CompanyRole::Staff] as $role) {
        $this->actingAs(S::member($this->company, $role))->put($url, ['min_stock_qty' => '3'])->assertForbidden();
    }

    $this->actingAs(S::member($this->company, CompanyRole::Manager))->from('/app/stock')
        ->put($url, ['min_stock_qty' => '6', 'max_stock_qty' => '30', 'reorder_qty' => '12.5'])->assertRedirect('/app/stock')->assertSessionHasNoErrors();

    $row = DB::table('products')->where('id', $this->apples)->first();
    expect((float) $row->min_stock_qty)->toBe(6.0)->and((float) $row->max_stock_qty)->toBe(30.0)->and((float) $row->reorder_qty)->toBe(12.5)
        ->and((int) $row->row_version)->toBe(2)->and($row->hub_hash)->not->toBeNull()
        ->and(DB::table('audit_logs')->where('action', 'product.updated')->where('subject_id', $this->apples)->count())->toBe(1);

    $this->actingAs($this->owner)->from('/app/stock')->put($url, ['min_stock_qty' => '9', 'max_stock_qty' => '3'])->assertSessionHasErrors('max_stock_qty');
    $this->actingAs($this->owner)->from('/app/stock')->put($url, ['min_stock_qty' => '-1'])->assertSessionHasErrors('min_stock_qty');

    // Unchanged: no new version, no audit. Cleared: back to null.
    $changed = app(CurrentCompany::class)->runAs($this->company, fn () => app(SetProductStockLevels::class)
        ->handle(Product::query()->findOrFail($this->apples), ['min_stock_qty' => '6', 'max_stock_qty' => '30.0000', 'reorder_qty' => '12.5']));
    expect($changed)->toBe([]);
    $this->actingAs($this->owner)->put($url, ['min_stock_qty' => '', 'max_stock_qty' => null, 'reorder_qty' => '12.5'])->assertSessionHasNoErrors();
    expect(DB::table('products')->where('id', $this->apples)->value('min_stock_qty'))->toBeNull()
        ->and((int) DB::table('products')->where('id', $this->apples)->value('row_version'))->toBe(3);

    expect(fn () => app(CurrentCompany::class)->runAs($this->company, fn () => app(SetProductStockLevels::class)
        ->handle(Product::query()->findOrFail($this->apples), ['min_stock_qty' => '5', 'max_stock_qty' => '1'])))->toThrow(ValidationException::class);
});
