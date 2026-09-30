<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.3: stock transfers between shops (read only): the list, one transfer, the discrepancy report and CSVs;
 * discrepancy math, relay visibility, the one-shop rule, who may look, and tenant isolation. "Now" is 30 Sept 2026.
 */

/**
 * A transfer as the sending shop's till pushed it, with its lines (product, sent, unit cost).
 *
 * @param  list<array{0: string, 1: string, 2: string}>  $lines
 * @return array{id: string, lines: list<string>}
 */
function transferRow(Company $company, Branch $from, Branch $to, string $reference, string $status, string $requestedAt, array $lines = [], array $columns = []): array
{
    $cost = '0';
    foreach ($lines as [, $qty, $unit]) {
        $cost = bcadd($cost, bcmul($qty, $unit, 4), 4);
    }
    $id = F::row('stock_transfers', $company, $from, [
        'reference' => $reference, 'from_branch_id' => $from->id, 'to_branch_id' => $to->id, 'status' => $status,
        'requested_at' => $requestedAt, 'requested_by_user_id' => '', 'dispatched_at' => $status === 'requested' ? null : $requestedAt,
        'dispatched_by_user_id' => '', 'dispatched_cost' => $cost, 'is_return' => false, 'note' => '', 'line_count' => count($lines), ...$columns,
    ]);
    $lineIds = array_map(fn (array $l) => F::row('stock_transfer_lines', $company, $from, [
        'transfer_id' => $id, 'product_id' => $l[0], 'product_name' => $l[0] === F::COLA ? 'Coca-Cola 500ml' : 'Water',
        'qty_requested' => $l[1], 'qty_dispatched' => $l[1], 'unit_cost' => $l[2],
    ]), $lines);

    return ['id' => $id, 'lines' => $lineIds];
}

/**
 * The receiving shop's receipt: one received quantity per transfer line.
 *
 * @param  array{id: string, lines: list<string>}  $transfer
 * @param  list<array{0: string, 1: string, 2: string}>  $lines  as sent
 * @param  list<string>  $received
 */
function receiptRow(Company $company, Branch $to, array $transfer, array $lines, array $received, string $at): void
{
    $receipt = F::row('stock_transfer_receipts', $company, $to, [
        'transfer_id' => $transfer['id'], 'status' => 'received', 'received_at' => $at, 'received_by_user_id' => '',
        'received_cost' => '0', 'variance_cost' => '0', 'note' => '',
    ]);
    foreach ($lines as $i => [$product, $qty, $unit]) {
        F::row('stock_transfer_receipt_lines', $company, $to, [
            'receipt_id' => $receipt, 'transfer_id' => $transfer['id'], 'transfer_line_id' => $transfer['lines'][$i], 'product_id' => $product,
            'qty_dispatched' => $qty, 'qty_received' => $received[$i], 'qty_variance' => bcsub($received[$i], $qty, 4), 'unit_cost' => $unit,
        ]);
    }
}

beforeEach(function () {
    $this->travelTo('2026-09-30 10:00:00');
    $this->sync = new SyncApiFixtures($this);
    [$this->company, $this->leeds, $this->bradford] = [$this->sync->company, $this->sync->leeds, $this->sync->bradford];
    $this->york = Branch::factory()->forCompany($this->company)->create(['code' => 'YRK', 'name' => 'York']);
    F::catalogue($this->company);
    $this->owner = F::member($this->company, CompanyRole::Owner);

    // Leeds → Bradford: 12 water and 24 cola sent, 4 cola missing on arrival (−4 × £0.78 = −£3.12).
    $sent = [[F::WATER, '12', '0.98'], [F::COLA, '24', '0.78']];
    $this->t1 = transferRow($this->company, $this->leeds, $this->bradford, 'TR-LDS-00001', 'dispatched', '2026-09-20 08:00:00', $sent);
    receiptRow($this->company, $this->bradford, $this->t1, $sent, ['12', '20'], '2026-09-21 10:00:00');
    // Bradford → Leeds, dispatched, not pulled by Leeds's till yet.
    $this->t2 = transferRow($this->company, $this->bradford, $this->leeds, 'TR-BRD-00001', 'dispatched', '2026-09-28 08:00:00', [[F::WATER, '10', '0.98']]);
    // Leeds → Bradford, still only requested (never relayed).
    $this->t3 = transferRow($this->company, $this->leeds, $this->bradford, 'TR-LDS-00002', 'requested', '2026-09-29 08:00:00', [[F::COLA, '6', '0.78']],
        ['note' => '=HYPERLINK("http://x")']);
    // Leeds → Bradford: one water too many arrived (+1 × £0.98).
    $over = [[F::WATER, '6', '0.98']];
    $this->t4 = transferRow($this->company, $this->leeds, $this->bradford, 'TR-LDS-00003', 'received', '2026-09-25 08:00:00', $over);
    receiptRow($this->company, $this->bradford, $this->t4, $over, ['7'], '2026-09-26 09:00:00');
    // Bradford → York, cancelled.
    $this->t5 = transferRow($this->company, $this->bradford, $this->york, 'TR-BRD-00002', 'cancelled', '2026-09-26 08:00:00', [[F::COLA, '2', '0.78']]);

    $this->props = fn (string $url, $user = null) => $this->actingAs($user ?? $this->owner)->get($url)->assertOk()->viewData('page')['props'];
    $this->refs = fn (array $props) => collect($props['rows']['data'])->pluck('reference')->sort()->values()->all();
});

test('guests go to the login page; staff get 403; owner, manager and accountant may look', function () {
    $urls = ['/app/transfers', '/app/transfers/discrepancies', "/app/transfers/{$this->t1['id']}", '/app/transfers/export', '/app/transfers/discrepancies/export'];
    foreach ($urls as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $staff = F::member($this->company, CompanyRole::Staff);
    foreach ($urls as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
    }

    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = F::member($this->company, $role);
        $this->actingAs($user)->get('/app/transfers')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/transfers/index'));
        $this->actingAs($user)->get('/app/transfers/discrepancies')->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/transfers/discrepancies'));
        $this->actingAs($user)->get("/app/transfers/{$this->t1['id']}")->assertOk()->assertInertia(fn (Assert $page) => $page->component('app/transfers/show'));
    }
    $this->actingAs($this->owner)->get('/app/transfers/01K5VB0000000000000NOPE001')->assertNotFound();
});

test('the list shows every transfer with where it is, the relay and its discrepancy at cost', function () {
    $props = ($this->props)('/app/transfers');
    $rows = collect($props['rows']['data'])->keyBy('reference');

    expect($rows->map(fn ($r) => [$r['status'], $r['relay']])->sortKeys()->all())->toBe([
        'TR-BRD-00001' => ['dispatched', 'waiting'],
        'TR-BRD-00002' => ['cancelled', 'notRelayed'],
        'TR-LDS-00001' => ['partlyReceived', 'received'],
        'TR-LDS-00002' => ['requested', 'notRelayed'],
        'TR-LDS-00003' => ['received', 'received'],
    ])->and($rows['TR-LDS-00001'])->toMatchArray([
        'from' => 'Leeds Kirkgate', 'to' => 'Bradford', 'lines' => 2, 'value' => '30.48', 'varianceValue' => '-3.12', 'discrepancies' => 1,
        'receivedAt' => '2026-09-21T10:00:00Z', 'requestedAt' => '2026-09-20T08:00:00Z', 'direction' => null,
    ])->and($rows['TR-LDS-00003']['varianceValue'])->toBe('0.98')
        ->and($rows['TR-BRD-00001']['varianceValue'])->toBeNull();

    $stats = collect($props['stats'])->keyBy('label');
    expect($stats['On the way']['value'])->toBe('1')
        ->and($stats['On the way']['hint'])->toBe('£9.80 at cost')
        ->and($stats['Not pulled yet']['value'])->toBe('1')
        ->and($stats['Received']['value'])->toBe('2')
        ->and($stats['Discrepancy at cost']['value'])->toBe('-2.14')
        ->and($stats['Discrepancy at cost']['hint'])->toBe('2 transfers with differences');

    // Filters: status, shop (from or to), flow, days (London), search; unknown values are ignored.
    expect(($this->refs)(($this->props)('/app/transfers?status=partlyReceived')))->toBe(['TR-LDS-00001'])
        ->and(($this->refs)(($this->props)('/app/transfers?shop='.$this->york->id)))->toBe(['TR-BRD-00002'])
        ->and(($this->refs)(($this->props)('/app/transfers?shop='.$this->leeds->id.'&flow=in')))->toBe(['TR-BRD-00001'])
        ->and(collect(($this->props)('/app/transfers?shop='.$this->leeds->id.'&flow=in')['rows']['data'])->pluck('direction')->all())->toBe(['in'])
        ->and(($this->refs)(($this->props)('/app/transfers?from=2026-09-27&to=2026-09-29')))->toBe(['TR-BRD-00001', 'TR-LDS-00002'])
        ->and(($this->refs)(($this->props)('/app/transfers?search=BRD-00002')))->toBe(['TR-BRD-00002'])
        ->and(count(($this->props)('/app/transfers?status=nope&flow=sideways&from=2026-02-31')['rows']['data']))->toBe(5);
});

test('one transfer: sent against received line by line, discrepancies at cost', function () {
    $props = ($this->props)("/app/transfers/{$this->t1['id']}");
    $lines = collect($props['lines'])->keyBy(fn ($l) => $l['product']['name']);

    expect($props['transfer'])->toMatchArray(['reference' => 'TR-LDS-00001', 'status' => 'partlyReceived', 'from' => 'Leeds Kirkgate', 'to' => 'Bradford', 'dispatchedCost' => '30.48'])
        ->and($lines['Coca-Cola 500ml'])->toMatchArray([
            'sent' => '24.0000', 'received' => '20.0000', 'variance' => '-4.0000', 'unitCost' => '0.7800',
            'sentValue' => '18.72', 'receivedValue' => '15.60', 'varianceValue' => '-3.12', 'discrepancy' => true,
        ])
        ->and($lines['Coca-Cola 500ml']['product']['sku'])->toBe('COLA-500')
        ->and($lines->except('Coca-Cola 500ml')->first())->toMatchArray(['variance' => '0.0000', 'varianceValue' => '0.00', 'discrepancy' => false])
        ->and($props['totals'])->toBe([
            'sent' => '36.0000', 'received' => '32.0000', 'variance' => '-4.0000', 'sentValue' => '30.48', 'receivedValue' => '27.36',
            'varianceValue' => '-3.12', 'discrepancies' => 1,
        ])
        ->and($props['relay']['transfer'])->toBe('received');

    // Not received yet: nothing to compare, no discrepancy.
    $open = ($this->props)("/app/transfers/{$this->t2['id']}");
    expect($open['receipt'])->toBeNull()
        ->and($open['totals'])->toMatchArray(['sent' => '10.0000', 'received' => null, 'varianceValue' => null, 'discrepancies' => 0])
        ->and($open['relay']['transfer'])->toBe('waiting');
});

test('relay: a dispatched transfer shows when the receiving till has pulled it, and its receipt when the sender has', function () {
    $relay = TillFixtures::sample('pull-reply.relay.json')['changes'];
    $this->sync->push(array_map(fn (array $row, int $n) => TillFixtures::envelope($row['entity'], $row['payload'], $n + 1, ['branchId' => $row['payload']['branchId']]),
        array_slice($relay, 0, 3), [0, 1, 2]))->assertOk();
    $id = $relay[0]['payload']['id'];
    $show = fn () => ($this->props)("/app/transfers/{$id}");

    expect($show()['relay']['transfer'])->toBe('waiting')->and($show()['transfer']['status'])->toBe('dispatched');

    $this->sync->pull(0)->assertOk(); // Leeds pulls: the rows get their versions, but they are not for Leeds.
    expect($show()['relay']['transfer'])->toBe('waiting');

    $bradford = $this->sync->pull(0, bradford: true)->assertOk();
    expect($show()['relay']['transfer'])->toBe('sent')->and($show()['transfer']['status'])->toBe('inTransit')
        ->and(collect(($this->props)('/app/transfers?status=inTransit')['rows']['data'])->pluck('relay', 'reference')->sortKeys()->all())
        ->toBe(['TR-BRD-00001' => 'sent', 'TR-LDS-00012' => 'sent']); // Leeds's pull above carried Bradford's transfer to it too

    $this->sync->pull($bradford->json('highestVersion'), bradford: true)->assertOk();
    expect($show()['relay']['transfer'])->toBe('stored');

    // Bradford receives 5 of the 6 loaves; the receipt goes back to Leeds.
    $receipt = Pull::payload('StockTransferReceipt', '01K5VB000000000000TRC00012', [
        'transferId' => $id, 'status' => 'received', 'receivedAt' => '2026-09-24T09:00:00Z', 'branchId' => TillFixtures::BRADFORD, 'closedAt' => null,
    ]);
    $line = Pull::payload('StockTransferReceiptLine', '01K5VB00000000000TRCN00121', [
        'receiptId' => $receipt['id'], 'transferId' => $id, 'transferLineId' => $relay[1]['payload']['id'], 'productId' => $relay[1]['payload']['productId'],
        'qtyDispatched' => 6, 'qtyReceived' => 5, 'qtyVariance' => -1, 'unitCost' => 0.92, 'branchId' => TillFixtures::BRADFORD,
    ]);
    $this->sync->push([
        TillFixtures::envelope('StockTransferReceipt', $receipt, 1, ['branchId' => TillFixtures::BRADFORD]),
        TillFixtures::envelope('StockTransferReceiptLine', $line, 2, ['branchId' => TillFixtures::BRADFORD]),
    ], bradford: true)->assertOk();

    $props = $show();
    expect($props['transfer']['status'])->toBe('partlyReceived')
        ->and($props['relay'])->toMatchArray(['transfer' => 'received', 'receipt' => 'waiting'])
        ->and($props['totals']['varianceValue'])->toBe('-0.92')
        ->and(collect($props['lines'])->pluck('missingOnReceipt', 'product.name')->all())
        ->toBe(['Coca-Cola 500ml' => true, 'Warburtons Toastie White Bread 800g' => false]); // the cola line is not on the receipt: not compared

    $leeds = $this->sync->pull(0)->assertOk();
    expect($show()['relay']['receipt'])->toBe('sent');
    $this->sync->pull($leeds->json('highestVersion'))->assertOk();
    expect($show()['relay']['receipt'])->toBe('stored');
});

test('the discrepancy report: per route and line by line, newest receipt first, over a period', function () {
    $props = ($this->props)('/app/transfers/discrepancies');

    expect($props['filters'])->toMatchArray(['from' => '2026-07-03', 'to' => '2026-09-30'])
        ->and($props['summary'])->toBe(['transfers' => 2, 'discrepant' => 2, 'short' => '4.0000', 'over' => '1.0000', 'sentValue' => '36.36', 'varianceValue' => '-2.14'])
        ->and($props['routes'])->toHaveCount(1)
        ->and($props['routes'][0])->toMatchArray(['from' => 'Leeds Kirkgate', 'to' => 'Bradford', 'transfers' => 2, 'varianceValue' => '-2.14'])
        ->and(array_map(fn ($l) => [$l['reference'], $l['product']['name'], $l['variance'], $l['varianceValue']], $props['lines']))->toBe([
            ['TR-LDS-00003', 'Warburtons Toastie White Bread 800g', '1.0000', '0.98'],
            ['TR-LDS-00001', 'Coca-Cola 500ml', '-4.0000', '-3.12'],
        ])
        ->and($props['truncated'])->toBeFalse();

    $later = ($this->props)('/app/transfers/discrepancies?from=2026-09-22&to=2026-09-30');
    expect($later['summary'])->toMatchArray(['transfers' => 1, 'short' => '0.0000', 'over' => '1.0000', 'varianceValue' => '0.98']);

    $csv = $this->actingAs($this->owner)->get('/app/transfers/discrepancies/export')->assertOk()->streamedContent();
    $rows = array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));
    expect($rows)->toHaveCount(3)
        ->and($rows[2])->toContain('TR-LDS-00001', '-4.0000', '-3.12', '2026-09-21 11:00');
});

test('the list CSV has every filtered transfer, times in London, and never a live formula', function () {
    $csv = $this->actingAs($this->owner)->get('/app/transfers/export')->assertOk()
        ->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->streamedContent();
    $rows = array_map(str_getcsv(...), array_filter(explode("\n", trim($csv))));

    expect($rows)->toHaveCount(6)
        ->and($rows[0][0])->toBe('Reference')
        ->and(collect($rows)->firstWhere(0, 'TR-LDS-00001'))->toContain('Partly received', 'Received', '2026-09-20 09:00', '30.48', '-3.12')
        ->and(collect($rows)->firstWhere(0, 'TR-LDS-00002'))->toContain('\'=HYPERLINK("http://x")');

    $only = $this->actingAs($this->owner)->get('/app/transfers/export?status=cancelled')->streamedContent();
    expect(substr_count(trim($only), "\n"))->toBe(1)->and($only)->toContain('TR-BRD-00002');
});

test('a one-shop user sees only transfers from or to their shop, whatever the URL says', function () {
    $manager = F::member($this->company, CompanyRole::Manager, $this->leeds);

    $props = ($this->props)('/app/transfers?shop='.$this->york->id, $manager);
    expect(($this->refs)($props))->toBe(['TR-BRD-00001', 'TR-LDS-00001', 'TR-LDS-00002', 'TR-LDS-00003'])
        ->and($props['oneShop'])->toBeTrue()
        ->and($props['filters']['shop'])->toBe($this->leeds->id)
        ->and(collect($props['shops'])->pluck('id')->all())->toBe([$this->leeds->id])
        ->and(collect($props['rows']['data'])->pluck('direction', 'reference')->sortKeys()->all())
        ->toBe(['TR-BRD-00001' => 'in', 'TR-LDS-00001' => 'out', 'TR-LDS-00002' => 'out', 'TR-LDS-00003' => 'out']);

    $this->actingAs($manager)->get("/app/transfers/{$this->t5['id']}")->assertNotFound();
    $this->actingAs($manager)->get("/app/transfers/{$this->t2['id']}")->assertOk();
    expect($this->actingAs($manager)->get('/app/transfers/export')->streamedContent())->not->toContain('TR-BRD-00002');

    $york = F::member($this->company, CompanyRole::Manager, $this->york);
    expect(($this->props)('/app/transfers/discrepancies', $york)['summary']['transfers'])->toBe(0)
        ->and(($this->refs)(($this->props)('/app/transfers', $york)))->toBe(['TR-BRD-00002']);
});

test('tenant isolation: another business never sees these transfers, nor they its', function () {
    $other = Company::factory()->create(['name' => 'Other Stores', 'multi_branch' => true, 'max_branches' => 3]);
    $a = Branch::factory()->forCompany($other)->create(['code' => 'AAA', 'name' => 'Alpha']);
    $b = Branch::factory()->forCompany($other)->create(['code' => 'BBB', 'name' => 'Beta']);
    $theirs = transferRow($other, $a, $b, 'TR-AAA-00001', 'dispatched', '2026-09-27 08:00:00', [[F::WATER, '3', '0.98']]);
    $otherOwner = F::member($other, CompanyRole::Owner);

    expect(($this->refs)(($this->props)('/app/transfers')))->not->toContain('TR-AAA-00001')
        ->and(($this->refs)(($this->props)('/app/transfers', $otherOwner)))->toBe(['TR-AAA-00001'])
        ->and(($this->props)('/app/transfers/discrepancies', $otherOwner)['summary']['transfers'])->toBe(0);
    $this->actingAs($this->owner)->get("/app/transfers/{$theirs['id']}")->assertNotFound();
    $this->actingAs($otherOwner)->get("/app/transfers/{$this->t1['id']}")->assertNotFound();
    expect($this->actingAs($otherOwner)->get('/app/transfers/export')->streamedContent())->not->toContain('TR-LDS');
});
