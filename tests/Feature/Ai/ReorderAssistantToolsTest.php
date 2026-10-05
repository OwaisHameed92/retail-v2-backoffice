<?php

use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ai\PortalAssistantHelpers;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Purchasing\ReorderFixtures as R;

/*
 * Module 6.4: the assistant's two write tools, through the 6.1 preview-then-confirm flow (FakeAiClient):
 * `suggest_reorder` (the reorder suggestions of one shop as draft orders) and `queue_labels` (the shelf-label queue).
 */

uses(PortalAssistantHelpers::class);

beforeEach(function () {
    $this->setUpPortal();
    F::catalogue($this->kirkgate);
    R::scenario($this->kirkgate, $this->leeds, $this->bradford);
    $this->orders = fn () => PurchaseOrder::withoutCompanyScope()->where('company_id', $this->kirkgate->id)->where('origin', 'headOffice');
});

test('suggest_reorder previews the shop\'s suggestions and drafts exactly them once confirmed', function () {
    $this->fake->callTool('suggest_reorder', ['shop_id' => $this->leeds->id])->replyWith('Here is what Leeds should order. Please confirm.');

    $done = $this->ask($this->owner, 'What should Leeds reorder?')['done'];
    $supplier = DB::table('suppliers')->value('name');

    expect($done['proposals'])->toHaveCount(1)
        ->and($done['proposals'][0]['preview'])->toBe("Draft an order from the reorder suggestions. Leeds from {$supplier}: 1 line, about £24.00 ex VAT. "
            .'Includes 2 × 24 Coca-Cola 500ml (1.7 days of stock left). They are saved as drafts; nothing is sent to a supplier.')
        ->and(collect($done['links'])->pluck('href')->all())->toContain('/app/purchasing/suggestions?shop='.$this->leeds->id)
        ->and(($this->orders)()->count())->toBe(0);

    $proposal = $this->actingAs($this->owner)->postJson('/app/assistant/actions/'.$done['proposals'][0]['id'].'/confirm')->assertOk()->json('proposal');
    $order = ($this->orders)()->sole();
    $line = PurchaseOrderLine::withoutCompanyScope()->where('purchase_order_id', $order->id)->sole();

    expect($proposal['status'])->toBe('confirmed')
        ->and($proposal['href'])->toBe('/app/purchasing/orders/'.$order->id)
        ->and([$order->branch_id, $order->status?->value, (int) $line->ordered_cases, $line->product_id])->toBe([$this->leeds->id, 'draft', 2, F::COLA])
        ->and(AuditLog::query()->where('action', 'purchase_order.head_office_drafted')->count())->toBe(1);
});

test('suggest_reorder takes the user\'s changes to the cases, and says so when nothing needs ordering', function () {
    $this->fake->callTool('suggest_reorder', ['shop_id' => $this->leeds->id, 'lines' => [['product_id' => F::COLA, 'cases' => 5]]])->replyWith('Please confirm.');

    $id = $this->ask($this->owner, 'Reorder Leeds but 5 cases of cola')['done']['proposals'][0]['id'];
    // toEqual: a MySQL JSON column does not keep the key order.
    expect(AiPendingAction::query()->find($id)->input['lines'])->toEqual([['product_id' => F::COLA, 'supplier_id' => F::SUPPLIER, 'cases' => 5]]);
    $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$id}/confirm")->assertOk();
    expect((int) PurchaseOrderLine::withoutCompanyScope()->where('purchase_order_id', ($this->orders)()->sole()->id)->sole()->ordered_cases)->toBe(5);

    // The draft now covers Leeds: nothing more to order, no proposal.
    $this->fake->callTool('suggest_reorder', ['shop_id' => $this->leeds->id])->replyWith('Nothing to order.');
    $done = $this->ask($this->owner, 'Anything else for Leeds?')['done'];
    expect($done['proposals'])->toBe([])->and($this->toolData(0)['linesToOrder'])->toBe(0);
});

test('suggest_reorder: a one-shop manager is refused, an accountant is not offered it, another business finds nothing', function () {
    $this->fake->callTool('suggest_reorder', ['shop_id' => $this->leeds->id])->replyWith('Sorry.');
    $this->ask($this->portalMember(CompanyRole::Manager, $this->leeds), 'Reorder Leeds');
    expect($this->fake->toolResultsIn()[0])->toMatchArray(['is_error' => true, 'content' => 'Only a user who can see every shop can draft head-office orders.']);

    $this->fake->callTool('suggest_reorder', ['shop_id' => $this->leeds->id])->replyWith('Sorry.');
    $this->ask($this->portalMember(CompanyRole::Accountant), 'Reorder Leeds');
    expect($this->fake->requests[2]->toolNames())->not->toContain('suggest_reorder')->toContain('get_stock');

    $this->fake->callTool('suggest_reorder', ['shop_id' => $this->leeds->id])->replyWith('Not found.');
    $this->ask($this->portalMember(CompanyRole::Owner, company: $this->other), 'Reorder Leeds');
    expect($this->fake->toolResultsIn()[0])->toMatchArray(['is_error' => true, 'content' => 'Not found in this business.'])
        ->and(AiPendingAction::query()->count())->toBe(0)
        ->and(($this->orders)()->count())->toBe(0);
});

test('queue_labels proposes, then adds the products to the shop\'s label queue once confirmed', function () {
    $this->fake->callTool('queue_labels', ['shop_id' => $this->bradford->id, 'product_ids' => [F::COLA, F::WATER]])->replyWith('Please confirm.');

    $done = $this->ask($this->owner, 'Print new labels for cola and bread at Bradford')['done'];
    expect($done['proposals'][0]['preview'])->toBe("Add shelf labels for 2 products to Bradford's label queue: Coca-Cola 500ml, Warburtons Toastie White Bread 800g. "
        .'Nothing is printed until someone prints the queue.')
        ->and(LabelQueueItem::withoutCompanyScope()->count())->toBe(0);

    $proposal = $this->actingAs($this->owner)->postJson('/app/assistant/actions/'.$done['proposals'][0]['id'].'/confirm')->assertOk()->json('proposal');

    expect($proposal['href'])->toBe('/app/labels?shop='.$this->bradford->id)
        ->and(LabelQueueItem::withoutCompanyScope()->where('branch_id', $this->bradford->id)->where('pending', true)->pluck('product_id')->sort()->values()->all())
        ->toBe([F::WATER, F::COLA])
        ->and(AuditLog::query()->where('action', 'labels.queued')->count())->toBe(1);
});

test('queue_labels keeps a one-shop manager on their shop and refuses unknown products and other businesses', function () {
    $manager = $this->portalMember(CompanyRole::Manager, $this->leeds);
    $this->fake->callTool('queue_labels', ['shop_id' => $this->bradford->id, 'product_ids' => [F::COLA]])->replyWith('Please confirm.');

    $id = $this->ask($manager, 'Label cola at Bradford')['done']['proposals'][0]['id'];
    $this->actingAs($manager)->postJson("/app/assistant/actions/{$id}/confirm")->assertOk();
    expect(LabelQueueItem::withoutCompanyScope()->pluck('branch_id')->all())->toBe([$this->leeds->id]);

    $this->fake->callTool('queue_labels', ['shop_id' => $this->leeds->id, 'product_ids' => ['01K5T0Q8C4000000000000P999']])->replyWith('Not found.');
    $this->ask($this->owner, 'Label a product');
    expect($this->fake->toolResultsIn()[0]['content'])->toBe('Some of those products were not found in this business or are not on sale.');

    $this->fake->callTool('queue_labels', ['shop_id' => $this->leeds->id, 'product_ids' => [F::COLA]])->replyWith('Not found.');
    $this->ask($this->portalMember(CompanyRole::Owner, company: $this->other), 'Label cola');
    expect($this->fake->toolResultsIn()[0]['content'])->toBe('Not found in this business.');

    $this->fake->callTool('queue_labels', ['shop_id' => $this->leeds->id, 'product_ids' => [F::COLA]])->replyWith('Sorry.');
    $this->ask($this->portalMember(CompanyRole::Accountant), 'Label cola');
    expect($this->fake->toolResultsIn()[0]['content'])->toContain('permission')
        ->and(LabelQueueItem::withoutCompanyScope()->count())->toBe(1);
});
