<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Module 5.2: the purchasing screens (read only): lists, documents, supplier statements; who may see them, one-shop
 * users and tenant isolation. "Now" is 30 Sept 2026.
 */
beforeEach(function () {
    $this->travelTo('2026-09-30 10:00:00');
    $this->sync = new SyncApiFixtures($this);
    [$this->company, $this->leeds, $this->bradford] = [$this->sync->company, $this->sync->leeds, $this->sync->bradford];
    F::catalogue($this->company);
    $this->owner = F::member($this->company, CompanyRole::Owner);

    // Leeds and Bradford both have a PO-000001: the reference tells them apart.
    $this->leedsPo = F::row('purchase_orders', $this->company, $this->leeds, ['supplier_id' => F::SUPPLIER, 'number' => 1, 'status' => 'partReceived', 'origin' => 'branch',
        'order_no' => 'PO-000001', 'branch_code' => 'LDS', 'reference' => 'PO-LDS-000001', 'net_total' => '100.00', 'vat_total' => '20.00', 'gross_total' => '120.00']);
    $this->bradfordPo = F::row('purchase_orders', $this->company, $this->bradford, ['supplier_id' => F::SUPPLIER, 'number' => 1, 'status' => 'sent', 'origin' => 'branch',
        'order_no' => 'PO-000001', 'branch_code' => 'BRD', 'reference' => 'PO-BRD-000001', 'net_total' => '50.00', 'vat_total' => '10.00', 'gross_total' => '60.00']);
    $this->grn = F::row('goods_receipts', $this->company, $this->leeds, ['supplier_id' => F::SUPPLIER, 'supplier_name' => 'Booker', 'purchase_order_id' => $this->leedsPo,
        'delivery_note_number' => 'DN-7781', 'received_date' => '2026-09-28', 'status' => 'posted', 'net_amount' => '23.52', 'vat_amount' => '0.00', 'gross_amount' => '23.52']);
    F::row('goods_receipt_lines', $this->company, $this->leeds, ['goods_receipt_id' => $this->grn, 'product_id' => F::WATER, 'expected_qty' => '24.0000',
        'received_qty' => '24.0000', 'damaged_qty' => '2.0000', 'case_qty' => 12, 'unit_cost' => '0.9800', 'vat_percentage' => '0.0000', 'is_short_or_over' => false]);
    $this->invoice = F::invoice($this->company, $this->leeds, '2026-09-28', '23.52', ['goods_receipt_id' => $this->grn, 'due_date' => '2026-09-29']);
    $this->return = F::row('purchase_returns', $this->company, $this->leeds, ['supplier_id' => F::SUPPLIER, 'supplier_name' => 'Booker', 'number' => 12, 'reference' => 'PR-LDS-00012',
        'status' => 'sent', 'return_date' => '2026-09-29', 'goods_receipt_id' => $this->grn, 'net_amount' => '1.96', 'vat_amount' => '0.00', 'gross_amount' => '1.96']);
    F::row('purchase_return_lines', $this->company, $this->leeds, ['purchase_return_id' => $this->return, 'product_id' => F::WATER, 'product_name' => 'Water', 'qty' => '2.0000',
        'unit_cost' => '0.9800', 'vat_percentage' => '0.0000', 'reason' => 'damaged', 'from_stock' => true, 'line_net' => '1.96', 'line_vat' => '0.00']);
});

test('guests go to the login page; staff get 403; owner, manager and accountant may look', function () {
    $urls = ['/app/purchasing', '/app/purchasing/orders', "/app/purchasing/orders/{$this->leedsPo}", "/app/purchasing/deliveries/{$this->grn}", '/app/purchasing/statements', '/app/purchasing/statements/'.F::SUPPLIER];
    foreach ($urls as $url) {
        $this->get($url)->assertRedirect('/login');
    }
    $staff = F::member($this->company, CompanyRole::Staff);
    foreach ($urls as $url) {
        $this->actingAs($staff)->get($url)->assertForbidden();
    }

    foreach ([CompanyRole::Owner, CompanyRole::Manager, CompanyRole::Accountant] as $role) {
        $user = F::member($this->company, $role);
        $this->actingAs($user)->get('/app/purchasing')->assertRedirect('/app/purchasing/orders');
        $this->actingAs($user)->get('/app/purchasing/orders')->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('app/purchasing/index')->where('can.manage', $role !== CompanyRole::Accountant));
    }
});

test('every list shows the shops\' own documents, by reference, with its summary', function () {
    $page = fn (string $url) => $this->actingAs($this->owner)->get($url)->assertOk()->viewData('page')['props'];

    $orders = $page('/app/purchasing/orders');
    expect(collect($orders['rows']['data'])->pluck('reference')->sort()->values()->all())->toBe(['PO-BRD-000001', 'PO-LDS-000001'])
        ->and($orders['tabs'])->toMatchArray(['orders' => 2, 'deliveries' => 1, 'invoices' => 1, 'returns' => 1, 'credit-notes' => 0])
        ->and(collect($orders['stats'])->firstWhere('label', 'Value on order')['value'])->toBe('180.00');

    expect(collect($page('/app/purchasing/orders?shop='.$this->bradford->id)['rows']['data'])->pluck('reference')->all())->toBe(['PO-BRD-000001'])
        ->and(collect($page('/app/purchasing/orders?search=LDS')['rows']['data'])->pluck('reference')->all())->toBe(['PO-LDS-000001'])
        ->and($page('/app/purchasing/orders?status=received')['rows']['data'])->toBe([])
        ->and($page('/app/purchasing/orders?origin=headOffice')['rows']['data'])->toBe([]);

    $deliveries = $page('/app/purchasing/deliveries')['rows']['data'];
    expect($deliveries[0])->toMatchArray(['reference' => 'DN-7781', 'order' => 'PO-LDS-000001', 'damaged' => '2.0000', 'lines' => 1, 'gross' => '23.52', 'shop' => 'Leeds Kirkgate']);

    $invoices = $page('/app/purchasing/invoices');
    expect($invoices['rows']['data'][0])->toMatchArray(['reference' => 'INV-2026-09-28', 'delivery' => 'DN-7781', 'overdue' => true, 'balance' => '23.52'])
        ->and(collect($invoices['stats'])->firstWhere('label', 'Overdue')['value'])->toBe('23.52');

    expect($page('/app/purchasing/returns')['rows']['data'][0])->toMatchArray(['reference' => 'PR-LDS-00012', 'status' => 'sent'])
        ->and($page('/app/purchasing/payments')['rows']['data'])->toBe([])
        ->and($page('/app/purchasing/rebates')['rows']['data'])->toBe([]);
    $this->actingAs($this->owner)->get('/app/purchasing/nope')->assertNotFound();
});

test('a delivery, invoice and return show their lines and links, read only', function () {
    $this->actingAs($this->owner)->get("/app/purchasing/deliveries/{$this->grn}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/purchasing/document')->where('document.reference', 'DN-7781')->where('lines.0.damaged', '2.0000')->where('lines.0.net', '23.52')
        ->where('links.0.reference', 'PO-LDS-000001')->where('links.1.reference', 'INV-2026-09-28'));

    $this->actingAs($this->owner)->get("/app/purchasing/invoices/{$this->invoice}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('kind', 'invoices')->where('links.0.kind', 'deliveries')->where('totals.gross', '23.52'));

    $this->actingAs($this->owner)->get("/app/purchasing/returns/{$this->return}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('document.reference', 'PR-LDS-00012')->where('lines.0.reason', 'damaged')->where('lines.0.product.name', 'Warburtons Toastie White Bread 800g'));

    // A document is only found under its own kind.
    $this->actingAs($this->owner)->get("/app/purchasing/invoices/{$this->grn}")->assertNotFound();
});

test('the supplier statement is invoices − credit notes − payments, with an opening balance and a running balance', function () {
    // Before the period (from 1 Sept): £100 invoiced, £10 credited, £40 paid → £50 opening.
    F::invoice($this->company, $this->bradford, '2026-08-10', '100.00');
    F::credit($this->company, $this->leeds, '2026-08-15', '10.00');
    F::payment($this->company, $this->leeds, '2026-08-20', '40.00');
    // In the period, beside the £23.52 invoice of 28 Sept: a £200.10 invoice, a £5.05 credit and a £150 payment.
    F::invoice($this->company, $this->bradford, '2026-09-05', '200.10');
    F::credit($this->company, $this->leeds, '2026-09-10', '5.05');
    F::payment($this->company, $this->leeds, '2026-09-12', '150.00');
    // Not counted: a draft invoice, a reversed payment, a deleted invoice.
    F::invoice($this->company, $this->leeds, '2026-09-13', '999.00', ['status' => 'draft']);
    F::payment($this->company, $this->leeds, '2026-09-14', '999.00', ['is_reversed' => true]);
    F::invoice($this->company, $this->leeds, '2026-09-15', '999.00', ['deleted_at' => '2026-09-16 00:00:00']);

    $this->actingAs($this->owner)->get('/app/purchasing/statements/'.F::SUPPLIER.'?from=2026-09-01&to=2026-09-30')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/purchasing/statement')
        ->where('opening', '50.00')
        ->where('entries', fn ($entries) => collect($entries)->map(fn ($e) => [$e['date'], $e['type'], $e['debit'], $e['credit'], $e['balance']])->all() === [
            ['2026-09-05', 'invoice', '200.10', '0.00', '250.10'],
            ['2026-09-10', 'credit', '0.00', '5.05', '245.05'],
            ['2026-09-12', 'payment', '0.00', '150.00', '95.05'],
            ['2026-09-28', 'invoice', '23.52', '0.00', '118.57'],
        ])
        ->where('totals', ['invoiced' => '223.62', 'credited' => '5.05', 'paid' => '150.00', 'reduced' => '155.05'])
        ->where('closing', '118.57'));

    // All time: 323.62 invoiced − 15.05 credited − 190.00 paid = 118.57, the same as the closing balance.
    $this->actingAs($this->owner)->get('/app/purchasing/statements')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('balances.0', ['id' => F::SUPPLIER, 'name' => 'Aire Valley Cash & Carry', 'invoiced' => '323.62', 'credited' => '15.05', 'paid' => '190.00', 'balance' => '118.57'])
        ->where('summary.owed', '118.57'));

    // Leeds alone: 23.52 − 15.05 − 190.00 = −181.53 (the supplier owes Leeds).
    $this->actingAs($this->owner)->get('/app/purchasing/statements?shop='.$this->leeds->id)->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('balances.0.balance', '-181.53')->where('summary.inCredit', '181.53')->where('summary.owed', '0.00'));
});

test('a one-shop user sees only their shop\'s documents and statement', function () {
    F::invoice($this->company, $this->bradford, '2026-09-05', '200.10');
    $manager = F::member($this->company, CompanyRole::Manager, $this->leeds);

    $props = $this->actingAs($manager)->get('/app/purchasing/orders?shop='.$this->bradford->id)->assertOk()->viewData('page')['props'];
    expect(collect($props['rows']['data'])->pluck('reference')->all())->toBe(['PO-LDS-000001'])
        ->and($props['filters']['shop'])->toBe($this->leeds->id)->and($props['oneShop'])->toBeTrue()->and($props['can']['manage'])->toBeFalse()
        ->and(collect($props['shops'])->pluck('id')->all())->toBe([$this->leeds->id])->and($props['tabs']['orders'])->toBe(1);

    $this->actingAs($manager)->get("/app/purchasing/orders/{$this->bradfordPo}")->assertNotFound();
    $this->actingAs($manager)->get("/app/purchasing/orders/{$this->leedsPo}")->assertOk();
    $bradfordGrn = F::row('goods_receipts', $this->company, $this->bradford, ['supplier_id' => F::SUPPLIER, 'delivery_note_number' => 'DN-B', 'received_date' => '2026-09-28', 'status' => 'posted']);
    $this->actingAs($manager)->get("/app/purchasing/deliveries/{$bradfordGrn}")->assertNotFound();

    $this->actingAs($manager)->get('/app/purchasing/statements/'.F::SUPPLIER.'?shop='.$this->bradford->id.'&from=2026-09-01&to=2026-09-30')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('shop', $this->leeds->id)->where('closing', '23.52')->has('entries', 1));
});

test('another business never sees these documents', function () {
    $other = Company::factory()->create();
    Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $otherOwner = F::member($other, CompanyRole::Owner);

    foreach (['orders', 'deliveries', 'invoices', 'returns'] as $kind) {
        $props = $this->actingAs($otherOwner)->get("/app/purchasing/{$kind}")->assertOk()->viewData('page')['props'];
        expect($props['rows']['data'])->toBe([])->and(array_sum($props['tabs']))->toBe(0);
    }

    $this->actingAs($otherOwner)->get("/app/purchasing/orders/{$this->leedsPo}")->assertNotFound();
    $this->actingAs($otherOwner)->get("/app/purchasing/deliveries/{$this->grn}")->assertNotFound();
    $this->actingAs($otherOwner)->get("/app/purchasing/invoices/{$this->invoice}")->assertNotFound();
    $this->actingAs($otherOwner)->get("/app/purchasing/returns/{$this->return}")->assertNotFound();
    $this->actingAs($otherOwner)->get('/app/purchasing/statements/'.F::SUPPLIER)->assertNotFound();
    $this->actingAs($otherOwner)->get('/app/purchasing/statements')->assertOk()->assertInertia(fn (Assert $page) => $page->where('balances', []));
});

test('security review L4: a delivery never shows another business\'s order, even when the till names its id', function () {
    $other = Company::factory()->create();
    $theirPo = F::row('purchase_orders', $other, Branch::factory()->forCompany($other)->create(), ['supplier_id' => F::SUPPLIER, 'number' => 9, 'status' => 'sent',
        'origin' => 'branch', 'order_no' => 'PO-SECRET', 'branch_code' => 'XXX', 'reference' => 'PO-SECRET-9', 'net_total' => '1.00', 'vat_total' => '0.00', 'gross_total' => '1.00']);
    DB::table('goods_receipts')->where('id', $this->grn)->update(['purchase_order_id' => $theirPo]);

    $this->actingAs($this->owner)->get('/app/purchasing/deliveries')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('rows.data.0.reference', 'DN-7781')->where('rows.data.0.order', null));
});
