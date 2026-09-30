<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Module 5.8: the shops' news deliveries, returns and credits, vouchers and the weekly summary (read only), their
 * figures, the one-shop rule and tenant isolation. "Now" is Wed 30 Sept 2026: this week is Mon 28 Sept – Sun 4 Oct.
 */
beforeEach(function () {
    $this->travelTo('2026-09-30 10:00:00');
    $this->sync = new SyncApiFixtures($this);
    [$this->company, $this->leeds, $this->bradford] = [$this->sync->company, $this->sync->leeds, $this->sync->bradford];
    F::catalogue($this->company);
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $guardian = '01K5T0Q8C40000000000NT0001';
    $yep = '01K5T0Q8C40000000000NT0002';
    Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', $guardian, ['name' => 'The Guardian', 'publisher' => 'GMG', 'coverPrice' => '2.80', 'isActive' => true]), ['branch_id' => null]);
    Pull::portalCreate($this->company, 'NewsTitle', Pull::payload('NewsTitle', $yep, ['name' => 'Yorkshire Evening Post', 'coverPrice' => '1.50', 'isActive' => true]), ['branch_id' => $this->leeds->id]);

    $delivery = fn (Branch $shop, string $date, string $status, array $lines, array $columns = []) => tap(
        F::row('news_deliveries', $this->company, $shop, ['supplier_id' => F::SUPPLIER, 'supplier_name' => 'Smiths News', 'delivery_date' => $date, 'status' => $status, 'notes' => '', ...$columns]),
        fn (string $id) => array_map(fn (array $l) => F::row('news_delivery_lines', $this->company, $shop, ['delivery_id' => $id, ...$l]), $lines),
    );
    $line = fn (string $title, string $name, int $in, int $sold, int $returned, string $unit, string $cost, string $credit) => [
        'title_id' => $title, 'title_name' => $name, 'qty_in' => $in, 'qty_sold' => $sold, 'qty_returned' => $returned, 'unit_cost' => $unit, 'line_cost' => $cost, 'return_value' => $credit,
    ];

    $this->leedsDelivery = $delivery($this->leeds, '2026-09-28', 'partReturned', [
        $line($guardian, 'The Guardian', 10, 7, 3, '2.1000', '21.0000', '6.30'),
        $line($yep, 'Yorkshire Evening Post', 20, 15, 5, '1.1000', '22.0000', '5.50'),
    ]);
    $this->bradfordDelivery = $delivery($this->bradford, '2026-09-29', 'settled', [$line($guardian, 'The Guardian', 5, 5, 0, '2.1000', '10.5000', '0.00')], ['credit_posted_at' => '2026-09-30 08:00:00']);
    $delivery($this->leeds, '2026-09-21', 'settled', [$line($guardian, 'The Guardian', 10, 10, 0, '2.1000', '21.0000', '0.00')]);

    $voucher = fn (Branch $shop, string $code, string $at, string $amount, ?string $claimed = null) => F::row('news_voucher_redemptions', $this->company, $shop, [
        'voucher_code' => $code, 'title_id' => $guardian, 'title_name' => 'The Guardian', 'amount' => $amount, 'register_id' => null,
        'redeemed_by_user_id' => 'U1', 'redeemed_at' => $at, 'claimed_at' => $claimed,
    ]);
    $voucher($this->leeds, 'HHDV-1001', '2026-09-29 08:00:00', '2.80');
    $voucher($this->leeds, 'HHDV-1002', '2026-09-29 09:00:00', '2.80', '2026-09-30 07:00:00');
    $voucher($this->bradford, 'SUB-2001', '2026-09-25 08:00:00', '1.50');

    $this->props = fn (string $url, $user = null) => $this->actingAs($user ?? $this->owner)->get($url)->assertOk()->viewData('page')['props'];
});

test('the weekly summary: sold vs returned, cost, credit, sales at cover price and margin, per shop and per title', function () {
    $props = ($this->props)('/app/news/summary');

    expect($props['week'])->toBe(['start' => '2026-09-28', 'end' => '2026-10-04', 'previous' => '2026-09-21', 'next' => null, 'current' => true])
        ->and($props['total'])->toMatchArray(['qtyIn' => 35, 'qtySold' => 27, 'qtyReturned' => 8, 'cost' => '53.50', 'credit' => '11.80', 'netCost' => '41.70',
            'sales' => '56.10', 'margin' => '14.40', 'marginPercent' => 25.7, 'vouchers' => 2, 'voucherValue' => '5.60'])
        ->and(collect($props['byShop'])->map(fn ($s) => [$s['shop'], $s['sales'], $s['margin'], $s['qtyReturned'], $s['voucherValue'] ?? null])->all())->toBe([
            ['Bradford', '14.00', '3.50', 0, null],
            ['Leeds Kirkgate', '42.10', '10.90', 8, '5.60'],
        ])
        ->and(collect($props['titles'])->map(fn ($t) => [$t['title'], $t['qtySold'], $t['sales'], $t['sellThrough']])->all())->toBe([
            ['The Guardian', 12, '33.60', 80.0],
            ['Yorkshire Evening Post', 15, '22.50', 75.0],
        ])
        ->and(array_slice($props['trend'], -2))->toBe([
            ['week' => '2026-09-21', 'sales' => '28.00', 'margin' => '7.00'],
            ['week' => '2026-09-28', 'sales' => '56.10', 'margin' => '14.40'],
        ]);

    $last = ($this->props)('/app/news/summary?week=2026-09-23');
    expect($last['week']['start'])->toBe('2026-09-21')->and($last['week']['next'])->toBe('2026-09-28')
        ->and($last['total'])->toMatchArray(['sales' => '28.00', 'vouchers' => 1, 'voucherValue' => '1.50']);
    expect(($this->props)('/app/news/summary?week=2027-01-04')['week']['start'])->toBe('2026-09-28')
        ->and(($this->props)('/app/news/summary?week=nonsense')['week']['start'])->toBe('2026-09-28')
        ->and(($this->props)('/app/news/summary?shop='.$this->bradford->id)['total']['sales'])->toBe('14.00');
});

test('deliveries, returns and credits and vouchers lists with their figures', function () {
    $deliveries = ($this->props)('/app/news/deliveries');
    $leeds = collect($deliveries['rows']['data'])->firstWhere('id', $this->leedsDelivery);
    expect($deliveries['rows']['meta']['total'])->toBe(3)
        ->and($deliveries['tabs'])->toBe(['titles' => 2, 'deliveries' => 3, 'returns' => 1, 'vouchers' => 3])
        ->and($leeds)->toMatchArray(['shop' => 'Leeds Kirkgate', 'qtyIn' => 30, 'qtySold' => 22, 'qtyReturned' => 8, 'cost' => '43.00', 'credit' => '11.80', 'status' => 'partReturned'])
        ->and(collect($deliveries['stats'])->pluck('value', 'label')->all())->toBe(['Deliveries' => '2', 'Copies in' => '35', 'Cost' => '53.50', 'Not settled' => '1']);

    $returns = ($this->props)('/app/news/returns');
    expect(collect($returns['rows']['data'])->pluck('id')->all())->toBe([$this->leedsDelivery])
        ->and($returns['rows']['data'][0])->toMatchArray(['status' => 'awaitingCredit', 'qtyReturned' => 8, 'gross' => '11.80', 'returnRate' => 26.7])
        ->and(collect($returns['stats'])->pluck('value', 'label')->all())->toBe(['Returned' => '8', 'Return credit' => '11.80', 'Awaiting credit' => '11.80', 'Return rate' => '17.8'])
        ->and(($this->props)('/app/news/returns?status=credited')['rows']['data'])->toBe([]);

    $vouchers = ($this->props)('/app/news/vouchers?supplier='.F::SUPPLIER);
    expect(collect($vouchers['rows']['data'])->pluck('status', 'reference')->all())->toBe(['HHDV-1002' => 'claimed', 'HHDV-1001' => 'unclaimed', 'SUB-2001' => 'unclaimed'])
        ->and(collect($vouchers['stats'])->pluck('value', 'label')->all())->toBe(['Taken this week' => '2', 'Value this week' => '5.60', 'Unclaimed' => '4.30', 'Claimed' => '2.80'])
        ->and(collect(($this->props)('/app/news/vouchers?status=claimed&search=HHDV')['rows']['data'])->pluck('reference')->all())->toBe(['HHDV-1002']);

    $detail = ($this->props)("/app/news/deliveries/{$this->leedsDelivery}");
    expect($detail['totals'])->toBe(['qtyIn' => 30, 'qtySold' => 22, 'qtyReturned' => 8, 'cost' => '43.00', 'credit' => '11.80', 'netCost' => '31.20', 'sales' => '42.10', 'margin' => '10.90'])
        ->and(collect($detail['lines'])->pluck('sales', 'title')->all())->toBe(['The Guardian' => '19.60', 'Yorkshire Evening Post' => '22.50'])
        ->and($detail['delivery'])->toMatchArray(['shop' => 'Leeds Kirkgate', 'status' => 'partReturned', 'creditPostedAt' => null]);
    $this->actingAs($this->owner)->get("/app/news/deliveries/{$this->leedsDelivery}")->assertInertia(fn (Assert $page) => $page->component('app/news/delivery'));
});

test('a one-shop user sees only their shop\'s deliveries, returns, vouchers and summary', function () {
    $user = F::member($this->company, CompanyRole::Accountant, $this->bradford);

    $summary = ($this->props)('/app/news/summary?shop='.$this->leeds->id, $user);
    expect($summary['shop'])->toBe($this->bradford->id)->and($summary['oneShop'])->toBeTrue()
        ->and(collect($summary['byShop'])->pluck('shop')->all())->toBe(['Bradford'])
        ->and($summary['total']['sales'])->toBe('14.00')->and($summary['titles'])->toHaveCount(1);

    $deliveries = ($this->props)('/app/news/deliveries?shop='.$this->leeds->id, $user);
    expect(collect($deliveries['rows']['data'])->pluck('id')->all())->toBe([$this->bradfordDelivery])
        ->and($deliveries['tabs'])->toBe(['titles' => 1, 'deliveries' => 1, 'returns' => 0, 'vouchers' => 1])
        ->and($deliveries['can'])->toBe(['manage' => false, 'everyShop' => false]);
    expect(collect(($this->props)('/app/news/titles', $user)['rows']['data'])->pluck('name')->all())->toBe(['The Guardian']);
    expect(collect(($this->props)('/app/news/vouchers', $user)['rows']['data'])->pluck('reference')->all())->toBe(['SUB-2001']);

    $this->actingAs($user)->get("/app/news/deliveries/{$this->leedsDelivery}")->assertNotFound();
    $this->actingAs($user)->get("/app/news/deliveries/{$this->bradfordDelivery}")->assertOk();
});

test('another business sees none of it and cannot open a delivery', function () {
    $other = Company::factory()->create();
    Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $otherOwner = F::member($other, CompanyRole::Owner);

    foreach (['titles', 'deliveries', 'returns', 'vouchers'] as $kind) {
        expect(($this->props)("/app/news/{$kind}", $otherOwner)['rows']['data'])->toBe([]);
    }

    $summary = ($this->props)('/app/news/summary', $otherOwner);
    expect($summary['byShop'])->toBe([])->and($summary['total']['sales'])->toBe('0.00')->and($summary['titles'])->toBe([]);
    $this->actingAs($otherOwner)->get("/app/news/deliveries/{$this->leedsDelivery}")->assertNotFound();
    $this->actingAs($otherOwner)->get('/app/news/summary?shop='.$this->leeds->id)->assertOk();
    expect(($this->props)('/app/news/summary?shop='.$this->leeds->id, $otherOwner)['total']['qtyIn'])->toBe(0);
});
