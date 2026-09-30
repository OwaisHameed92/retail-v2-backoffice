<?php

use App\Domain\Purchasing\Support\ReorderSuggestion;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 5.2: head-office orders drafted, changed, sent and cancelled from the portal, pulled by that shop only. */
beforeEach(function () {
    $this->travelTo('2026-09-24 08:30:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    F::catalogue($this->company);
    $this->owner = F::member($this->company, CompanyRole::Owner);
    $this->since = $this->sync->pull(0)->json('highestVersion');
    $this->order = fn () => PurchaseOrder::withoutCompanyScope()->where('company_id', $this->company->id)->sole();
});

test('the owner drafts an order for Leeds; only Leeds pulls it, with VAT from the rate, never from the browser', function () {
    $this->actingAs($this->owner)->get('/app/purchasing/orders/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/purchasing/order-form')->has('shops', 2)->has('vatRates', 2)->where('order', null));

    $this->actingAs($this->owner)->post('/app/purchasing/orders', F::orderInput($this->sync->leeds))->assertSessionHasNoErrors();
    $order = ($this->order)();

    expect($order->reference)->toBe('HO-LDS-000001')->and($order->status?->value)->toBe('draft')->and($order->branch_id)->toBe($this->sync->leeds->id)
        ->and([$order->net_total, $order->vat_total, $order->gross_total])->toBe(['60.96', '7.49', '68.45'])
        ->and(PurchaseOrderLine::withoutCompanyScope()->where('purchase_order_id', $order->id)->orderBy('position')->pluck('vat_percentage')->all())->toBe(['0.0000', '20.0000']);

    $leeds = Pull::changes($this->sync->pull($this->since));
    expect(array_column($leeds, 'entity'))->toBe(['PurchaseOrder', 'PurchaseOrderLine', 'PurchaseOrderLine'])
        ->and($leeds[0]['payload'])->toMatchArray(['origin' => 'headOffice', 'status' => 'draft', 'reference' => 'HO-LDS-000001'])
        ->and(Pull::changes($this->sync->pull($this->since, bradford: true)))->toBe([]);

    $this->actingAs($this->owner)->get("/app/purchasing/orders/{$order->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/purchasing/order')->where('order.reference', 'HO-LDS-000001')->where('order.withPortal', true)
        ->where('can', ['manage' => true, 'edit' => true, 'send' => true, 'cancel' => true])->has('lines', 2)->where('lines.0.product.name', 'Warburtons Toastie White Bread 800g')
        ->where('totals.gross', '68.45'));
});

test('the portal changes, sends and cancels its order until the shop takes it on', function () {
    $this->actingAs($this->owner)->post('/app/purchasing/orders', F::orderInput($this->sync->leeds));
    $order = ($this->order)();

    $this->actingAs($this->owner)->get("/app/purchasing/orders/{$order->id}/edit")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/purchasing/order-form')->where('order.reference', 'HO-LDS-000001')->has('order.lines', 2)->where('shopId', $this->sync->leeds->id));

    $input = F::orderInput(null, 'draft', ['lines' => [F::orderInput(null)['lines'][1]]]);
    $this->actingAs($this->owner)->put("/app/purchasing/orders/{$order->id}", $input)->assertRedirect("/app/purchasing/orders/{$order->id}");
    expect($order->fresh()->gross_total)->toBe('44.93')
        ->and(PurchaseOrderLine::withoutCompanyScope()->where('purchase_order_id', $order->id)->count())->toBe(1);

    $this->actingAs($this->owner)->post("/app/purchasing/orders/{$order->id}/send")->assertSessionHas('success');
    expect($order->fresh()->status?->value)->toBe('sent')->and($order->fresh()->sent_at)->not->toBeNull();

    $this->actingAs($this->owner)->post("/app/purchasing/orders/{$order->id}/send")->assertSessionHasErrors('order');
    $this->actingAs($this->owner)->post("/app/purchasing/orders/{$order->id}/cancel", ['reason' => 'Supplier out of stock'])->assertSessionHas('success');
    expect($order->fresh()->status?->value)->toBe('cancelled')->and($order->fresh()->cancel_reason)->toBe('Supplier out of stock');

    // A cancelled order is never re-opened.
    $this->actingAs($this->owner)->put("/app/purchasing/orders/{$order->id}", F::orderInput(null, 'sent'))->assertSessionHasErrors('order');
    $this->actingAs($this->owner)->get("/app/purchasing/orders/{$order->id}/edit")->assertRedirect("/app/purchasing/orders/{$order->id}");
    expect($order->fresh()->status?->value)->toBe('cancelled');
});

test('once the shop pushes the order it is the shop\'s: read only on the portal', function () {
    $this->actingAs($this->owner)->post('/app/purchasing/orders', F::orderInput($this->sync->leeds, 'sent'));
    $order = ($this->order)();
    $pulled = Pull::changes($this->sync->pull($this->since))[0]['payload'];
    $this->sync->push([TillFixtures::envelope('PurchaseOrder', [...$pulled, 'status' => 'partReceived', 'branchCode' => 'LDS', 'rowVersion' => 2], 1, ['op' => 'U', 'branchId' => TillFixtures::LEEDS])])->assertOk();

    $this->actingAs($this->owner)->get("/app/purchasing/orders/{$order->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('order.withPortal', false)->where('can.edit', false)->where('can.cancel', false)->where('lockedReason', fn ($r) => str_contains($r, 'Draft a new order instead')));
    $this->actingAs($this->owner)->get("/app/purchasing/orders/{$order->id}/edit")->assertRedirect("/app/purchasing/orders/{$order->id}")->assertSessionHas('error');
    $this->actingAs($this->owner)->put("/app/purchasing/orders/{$order->id}", F::orderInput(null, 'sent'))->assertSessionHasErrors('order');
    $this->actingAs($this->owner)->post("/app/purchasing/orders/{$order->id}/cancel")->assertSessionHasErrors('order');
    expect($order->fresh()->status?->value)->toBe('partReceived');

    // A shop's own order: never editable here.
    $own = F::row('purchase_orders', $this->company, $this->sync->bradford, ['supplier_id' => F::SUPPLIER, 'number' => 1, 'status' => 'sent', 'origin' => 'branch',
        'order_no' => 'PO-000001', 'branch_code' => 'BRD', 'reference' => 'PO-BRD-000001', 'net_total' => '10.00', 'vat_total' => '2.00', 'gross_total' => '12.00']);
    $this->actingAs($this->owner)->get("/app/purchasing/orders/{$own}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('order.reference', 'PO-BRD-000001')->where('can.edit', false)->where('lockedReason', fn ($r) => str_contains($r, 'shop\'s own order')));
    $this->actingAs($this->owner)->put("/app/purchasing/orders/{$own}", F::orderInput(null))->assertSessionHasErrors('order');
});

test('who may order: owner and every-shop manager; one-shop managers, accountants and staff may not', function () {
    $this->get('/app/purchasing/orders/create')->assertRedirect('/login');
    $this->post('/app/purchasing/orders', F::orderInput($this->sync->leeds))->assertRedirect('/login');
    $manager = F::member($this->company, CompanyRole::Manager);
    $this->actingAs($manager)->post('/app/purchasing/orders', F::orderInput($this->sync->bradford, 'sent'))->assertSessionHasNoErrors();
    expect(($this->order)()->reference)->toBe('HO-BRD-000001');
    $id = ($this->order)()->id;

    foreach ([F::member($this->company, CompanyRole::Manager, $this->sync->leeds), F::member($this->company, CompanyRole::Accountant), F::member($this->company, CompanyRole::Staff)] as $user) {
        $this->actingAs($user)->get('/app/purchasing/orders/create')->assertForbidden();
        $this->actingAs($user)->post('/app/purchasing/orders', F::orderInput($this->sync->leeds))->assertForbidden();
        $this->actingAs($user)->put("/app/purchasing/orders/{$id}", F::orderInput(null))->assertForbidden();
        $this->actingAs($user)->post("/app/purchasing/orders/{$id}/send")->assertForbidden();
        $this->actingAs($user)->post("/app/purchasing/orders/{$id}/cancel")->assertForbidden();
    }

    expect(PurchaseOrder::withoutCompanyScope()->count())->toBe(1)->and(($this->order)()->status?->value)->toBe('sent');
});

test('another business\'s shop, supplier, products and orders are out of reach', function () {
    $other = Company::factory()->create();
    $otherShop = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $otherOwner = F::member($other, CompanyRole::Owner);
    $this->actingAs($this->owner)->post('/app/purchasing/orders', F::orderInput($this->sync->leeds));
    $order = ($this->order)();

    $this->actingAs($otherOwner)->post('/app/purchasing/orders', F::orderInput($this->sync->leeds))->assertNotFound();
    $this->actingAs($otherOwner)->post('/app/purchasing/orders', F::orderInput($otherShop))->assertSessionHasErrors(['supplierId', 'lines']);
    $this->actingAs($otherOwner)->get("/app/purchasing/orders/{$order->id}")->assertNotFound();
    $this->actingAs($otherOwner)->put("/app/purchasing/orders/{$order->id}", F::orderInput(null))->assertNotFound();
    $this->actingAs($otherOwner)->post("/app/purchasing/orders/{$order->id}/cancel")->assertNotFound();
    $this->actingAs($this->owner)->post('/app/purchasing/orders', F::orderInput($otherShop))->assertNotFound();

    expect(PurchaseOrder::withoutCompanyScope()->count())->toBe(1)->and($order->fresh()->status?->value)->toBe('draft');
});

test('the form suggests cases from the shop\'s stock and reorder levels', function () {
    DB::table('product_suppliers')->insert([
        ['id' => '01K5T0Q8C40000000000PS0001', 'company_id' => $this->company->id, 'product_id' => F::COLA, 'supplier_id' => F::SUPPLIER, 'case_qty' => 24, 'case_cost' => '18.7200', 'is_preferred' => true, 'row_version' => 0],
        ['id' => '01K5T0Q8C40000000000PS0002', 'company_id' => $this->company->id, 'product_id' => F::WATER, 'supplier_id' => F::SUPPLIER, 'case_qty' => 12, 'case_cost' => '0', 'is_preferred' => false, 'row_version' => 0],
    ]);
    F::row('branch_products', $this->company, $this->sync->leeds, ['product_id' => F::COLA, 'qty_on_hand' => '5.0000', 'reorder_point' => '10.0000', 'max_qty' => '60.0000']);

    $this->actingAs($this->owner)->get("/app/purchasing/orders/create?shop={$this->sync->leeds->id}&supplier=".F::SUPPLIER.'&q=toastie')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('shopId', $this->sync->leeds->id)->has('suggestions', 2)
            ->where('suggestions.0.name', 'Coca-Cola 500ml')->where('suggestions.0.unitCost', '0.7800')->where('suggestions.0.suggestedCases', 3)->where('suggestions.0.onHand', '5.0000')
            ->where('suggestions.1.suggestedCases', 0)->where('suggestions.1.unitCost', '0.9800')->where('suggestions.1.onHand', null)
            ->has('results', 1)->where('results.0.productId', F::WATER));

    expect(ReorderSuggestion::cases('5', '10', '60', null, 24))->toBe(3)
        ->and(ReorderSuggestion::cases('11', '10', '60', null, 24))->toBe(0)
        ->and(ReorderSuggestion::cases('0', '4', null, '6', 12))->toBe(1)
        ->and(ReorderSuggestion::cases('0', '4', null, null, 6))->toBe(2)
        ->and(ReorderSuggestion::cases(null, '4', '10', null, 6))->toBe(0);
});
