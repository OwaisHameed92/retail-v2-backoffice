<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\DraftHeadOfficeOrder;
use App\Domain\TillData\Data\HeadOfficeOrderData;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.9B: head-office purchase orders drafted on the portal, pulled by one shop (contract v1.4.1 §10.6, §21.4). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-24 08:30:00');
    $this->sample = TillFixtures::sample('pull-reply.head-office.json')['changes'];

    Pull::portalCreate($this->company, 'Supplier', $this->sample[0]['payload']);
    Pull::portalCreate($this->company, 'VatRate', TillFixtures::sample('entities/VatRate.json'));
    Pull::portalCreate($this->company, 'VatRate', [...TillFixtures::sample('entities/VatRate.json'), 'id' => '01K5T0Q8C4000000000000V002', 'name' => 'Zero']);
    Pull::portalCreate($this->company, 'Product', TillFixtures::sample('entities/Product.json'));
    Pull::portalCreate($this->company, 'Product', [...TillFixtures::sample('entities/Product.json'), 'id' => '01K5T0Q8C4000000000000P002', 'name' => 'Coca-Cola 500ml']);
    $this->since = 5;   // supplier, two VAT rates, two products

    // The sample order (HO-000123 for Leeds): sent, two lines, £60.96 + £7.49 VAT.
    $this->input = fn (array $overrides = []) => HeadOfficeOrderData::fromArray([
        'supplierId' => '01K5T0Q8C4000000000000S001', 'status' => 'sent', 'expectedDate' => '2026-09-25',
        'notes' => 'Weekly top-up agreed with head office',
        'lines' => array_map(fn (array $c) => [
            'productId' => $c['payload']['productId'], 'orderedCases' => $c['payload']['orderedCases'],
            'caseQty' => $c['payload']['caseQtySnapshot'], 'unitCost' => $c['payload']['unitCostSnapshot'],
            'vatRateId' => $c['payload']['vatRateId'], 'vatPercentage' => $c['payload']['vatPercentage'],
        ], array_slice($this->sample, 4)),
        ...$overrides,
    ]);
    $this->draft = fn (?Branch $branch = null, array $overrides = [], ?string $id = null) => app(DraftHeadOfficeOrder::class)
        ->handle($branch ?? $this->sync->leeds, ($this->input)($overrides), $id);
});

test('replays pull-reply.head-office.json: the order and its lines reach Leeds only, in the till\'s shape', function () {
    $order = ($this->draft)();

    expect($order->reference)->toBe('HO-LDS-000001')
        ->and([$order->net_total, $order->vat_total, $order->gross_total])->toBe(['60.96', '7.49', '68.45'])
        ->and(Pull::changes($this->sync->pull($this->since, bradford: true)))->toBe([]);

    $reply = $this->sync->pull($this->since)->assertOk();
    expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
    $changes = Pull::changes($reply);
    $sample = array_slice($this->sample, 3);

    expect(array_column($changes, 'entity'))->toBe(['PurchaseOrder', 'PurchaseOrderLine', 'PurchaseOrderLine'])
        ->and(array_column($changes, 'branchId'))->toBe(array_fill(0, 3, TillFixtures::LEEDS))
        ->and(array_column($changes, 'op'))->toBe(['I', 'I', 'I']);

    $ours = ['id', 'number', 'orderNo', 'reference', 'branchCode', 'purchaseOrderId', 'rowVersion'];
    foreach ($changes as $i => $change) {
        $derived = ['isFromHeadOffice', 'hasReceivingStarted', 'net', 'vat', 'gross', 'isEditable', 'isOpen', 'unitCost', 'lineNet', 'lineVat', 'lineGross', 'isFullyReceived'];
        expect(array_diff_key($change['payload'], array_flip($ours)))->toEqual(array_diff_key($sample[$i]['payload'], array_flip([...$ours, ...$derived])));
    }

    expect($changes[0]['payload'])->toMatchArray(['origin' => 'headOffice', 'number' => 1, 'orderNo' => 'HO-000001', 'branchCode' => '', 'reference' => 'HO-LDS-000001'])
        ->and(array_column(array_column(array_slice($changes, 1), 'payload'), 'receivedQty'))->toBe([0, 0])
        ->and(array_column(array_column(array_slice($changes, 1), 'payload'), 'purchaseOrderId'))->toBe([$order->id, $order->id]);

    // Sent twice is still one order: the same versions, nothing after them.
    expect(Pull::summary($this->sync->pull($this->since)))->toBe(Pull::summary($reply))
        ->and(Pull::changes($this->sync->pull($reply->json('highestVersion'))))->toBe([])
        ->and(AuditLog::query()->where('action', 'purchase_order.head_office_drafted')->count())->toBe(1);
});

test('the portal may change the order until the shop owns it; a removed line is pulled as D', function () {
    $order = ($this->draft)(overrides: ['status' => 'draft']);
    $since = $this->sync->pull($this->since)->json('highestVersion');

    $this->travel(1)->minutes();
    $changed = ($this->draft)(overrides: ['status' => 'sent', 'lines' => [['productId' => '01K5T0Q8C4000000000000P001', 'orderedCases' => 3, 'caseQty' => 12, 'unitCost' => '0.98', 'vatRateId' => '01K5T0Q8C4000000000000V002', 'vatPercentage' => 0]]], id: $order->id);

    expect($changed->id)->toBe($order->id)->and($changed->gross_total)->toBe('35.28');
    expect(array_map(fn ($c) => [$c['entity'], $c['op']], Pull::changes($this->sync->pull($since))))->toBe([
        ['PurchaseOrder', 'U'], ['PurchaseOrderLine', 'U'], ['PurchaseOrderLine', 'D'],
    ]);
});

test('once the shop books goods in, its pushes are the truth, never echoed, and the portal\'s changes are refused', function () {
    $order = ($this->draft)();
    $first = $this->sync->pull($this->since);
    [$pulled, $since] = [Pull::changes($first), $first->json('highestVersion')];

    // The shop's goods-in: the order at partReceived and a line's receivedQty come up as the shop's rows.
    $shopOrder = [...$pulled[0]['payload'], 'status' => 'partReceived', 'branchCode' => 'LDS', 'rowVersion' => 2, 'updatedAt' => '2026-09-25T09:00:00Z'];
    $shopLine = [...$pulled[1]['payload'], 'receivedQty' => 24, 'rowVersion' => 2, 'updatedAt' => '2026-09-25T09:00:00Z'];
    $this->sync->push([
        TillFixtures::envelope('PurchaseOrder', $shopOrder, 1, ['op' => 'U', 'branchId' => TillFixtures::LEEDS]),
        TillFixtures::envelope('PurchaseOrderLine', $shopLine, 2, ['op' => 'U']),
    ])->assertOk()->assertJsonPath('accepted', 2);

    $stored = PurchaseOrder::withoutCompanyScope()->findOrFail($order->id);
    expect($stored->status?->value)->toBe('partReceived')
        ->and(Pull::changes($this->sync->pull($since)))->toBe([])
        ->and(collect(Pull::changes($this->sync->pull(0, bradford: true)))->pluck('entity'))->not->toContain('PurchaseOrder');

    expect(fn () => ($this->draft)(overrides: ['notes' => 'Changed'], id: $order->id))
        ->toThrow(ValidationException::class, 'Draft a new order instead');
});

test('a draft the shop cancels cannot be sent again from the portal; a cancelled order is never re-opened', function () {
    $draft = ($this->draft)(overrides: ['status' => 'draft']);
    $pulled = Pull::changes($this->sync->pull($this->since))[0]['payload'];
    $this->sync->push([TillFixtures::envelope('PurchaseOrder', [...$pulled, 'status' => 'cancelled', 'rowVersion' => 2], 1, ['op' => 'U', 'branchId' => TillFixtures::LEEDS])])->assertOk();

    expect(fn () => ($this->draft)(overrides: ['status' => 'sent'], id: $draft->id))->toThrow(ValidationException::class, 'Draft a new order instead');

    $cancelled = ($this->draft)(overrides: ['status' => 'cancelled', 'cancelReason' => 'Supplier out of stock']);
    expect($cancelled->reference)->toBe('HO-LDS-000002')
        ->and($cancelled->cancel_reason)->toBe('Supplier out of stock')
        ->and(fn () => ($this->draft)(overrides: ['status' => 'sent'], id: $cancelled->id))->toThrow(ValidationException::class, 'cannot be re-opened');
});

test('each shop has its own head-office numbers; a shop\'s own orders are never sent down', function () {
    expect(($this->draft)($this->sync->bradford)->reference)->toBe('HO-BRD-000001')
        ->and(($this->draft)()->reference)->toBe('HO-LDS-000001');

    $own = [...Pull::payload('PurchaseOrder', '01K5W2B9J000000000P0000001', ['origin' => 'branch', 'status' => 'sent', 'branchId' => TillFixtures::LEEDS, 'supplierId' => '01K5T0Q8C4000000000000S001'])];
    $this->sync->push([TillFixtures::envelope('PurchaseOrder', $own, 1)])->assertOk();

    expect(collect(Pull::changes($this->sync->pull(0)))->pluck('entityId'))->not->toContain('01K5W2B9J000000000P0000001')
        ->and(collect(Pull::changes($this->sync->pull(0, bradford: true)))->pluck('entityId'))->not->toContain('01K5W2B9J000000000P0000001')
        ->and(DB::table('purchase_orders')->where('id', '01K5W2B9J000000000P0000001')->value('hub_version'))->toBe(0);
});

test('validation: a supplier, products and VAT rates of this business, a head-office status, at least one unit', function (array $overrides, string $field) {
    expect(fn () => ($this->draft)(overrides: $overrides))->toThrow(ValidationException::class);

    try {
        ($this->draft)(overrides: $overrides);
    } catch (ValidationException $e) {
        expect(array_keys($e->errors()))->toContain($field);
    }
})->with([
    'unknown supplier' => [['supplierId' => '01K5T0Q8C4000000000000S999'], 'supplierId'],
    'not a head-office status' => [['status' => 'received'], 'status'],
    'no lines' => [['lines' => []], 'lines'],
    'unknown product' => [['lines' => [['productId' => '01K5T0Q8C4000000000000P999', 'orderedCases' => 1, 'caseQty' => 1, 'unitCost' => 1, 'vatRateId' => '01K5T0Q8C4000000000000V001', 'vatPercentage' => 20]]], 'lines'],
    'nothing ordered' => [['lines' => [['productId' => '01K5T0Q8C4000000000000P001', 'orderedCases' => 0, 'caseQty' => 12, 'unitCost' => 1, 'vatRateId' => '01K5T0Q8C4000000000000V001', 'vatPercentage' => 20]]], 'lines.0'],
    'bad date' => [['expectedDate' => '25/09/2026'], 'expectedDate'],
]);

test('tenant isolation: another business cannot order this business\'s supplier or products for its shop', function () {
    $other = Company::factory()->create();
    $shop = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);

    expect(fn () => ($this->draft)($shop))->toThrow(ValidationException::class)
        ->and(DB::table('purchase_orders')->count())->toBe(0);
});
