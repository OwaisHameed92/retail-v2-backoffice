<?php

use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Enums\PurchaseOrderStatus;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Ai\PortalAssistantHelpers;
use Tests\Feature\Purchasing\PurchasingFixtures as F;

/*
 * Module 6.2: a change asked of the assistant ("draft an order for 2 cases of cola") only goes through the 6.1
 * preview-then-confirm flow: the write tool proposes, nothing changes until the same user confirms, and then the real
 * Action (SaveHeadOfficeOrder) runs once, with audit entries. One-shop users and other businesses cannot.
 */

uses(PortalAssistantHelpers::class);

beforeEach(function () {
    $this->setUpPortal();
    F::catalogue($this->kirkgate);
    DB::table('product_suppliers')->insert(['id' => '01K5T0Q8C4000000000000PS01', 'company_id' => $this->kirkgate->id, 'product_id' => F::COLA,
        'supplier_id' => F::SUPPLIER, 'case_qty' => 24, 'case_cost' => '12.0000', 'is_preferred' => true, 'row_version' => 1]);
    $this->orders = fn () => PurchaseOrder::withoutCompanyScope()->where('company_id', $this->kirkgate->id);
    $this->draft = ['shop_id' => $this->leeds->id, 'supplier_id' => F::SUPPLIER, 'lines' => [['product_id' => F::COLA, 'cases' => 2]]];
});

test('the assistant finds the product, proposes a draft order, and nothing is ordered until the user confirms', function () {
    $this->fake->callTool('find_products', ['search' => 'cola'])
        ->callTool('draft_purchase_order', $this->draft)
        ->replyWith('I have prepared a draft order for 2 cases of Coca-Cola for Leeds. Please confirm it below.');

    $done = $this->ask($this->owner, 'Draft an order for 2 cases of Coke for Leeds')['done'];

    $found = $this->toolData(0, request: 1)['products'][0];
    expect($found)->toMatchArray(['productId' => F::COLA, 'name' => 'Coca-Cola 500ml'])
        ->and($found['suppliers'][0])->toMatchArray(['supplierId' => F::SUPPLIER, 'caseQty' => 24, 'caseCost' => '12.0000'])
        ->and($done['proposals'])->toHaveCount(1)
        ->and($done['proposals'][0]['status'])->toBe('pending')
        ->and($done['proposals'][0]['preview'])->toBe('Draft a head-office order for Leeds from '.DB::table('suppliers')->value('name')
            .': 2 × 24 Coca-Cola 500ml (£0.5000 each). Cost about £24.00 ex VAT. It is saved as a draft; nothing is sent to the supplier.')
        ->and(($this->orders)()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'ai.action_proposed')->count())->toBe(1);

    $actionId = $done['proposals'][0]['id'];

    // Another user of the same business, or of another business, cannot confirm it.
    $this->actingAs($this->portalMember(CompanyRole::Manager))->postJson("/app/assistant/actions/{$actionId}/confirm")->assertNotFound();
    $this->actingAs($this->portalMember(CompanyRole::Owner, company: $this->other))->postJson("/app/assistant/actions/{$actionId}/confirm")->assertNotFound();
    expect(($this->orders)()->count())->toBe(0);

    $proposal = $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$actionId}/confirm")->assertOk()->json('proposal');
    $order = ($this->orders)()->sole();

    expect($proposal['status'])->toBe('confirmed')
        ->and($proposal['href'])->toBe('/app/purchasing/orders/'.$order->id)
        ->and($order->status)->toBe(PurchaseOrderStatus::Draft)
        ->and($order->branch_id)->toBe($this->leeds->id)
        ->and(AuditLog::query()->where('action', 'ai.action_confirmed')->count())->toBe(1);

    // It runs once.
    $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$actionId}/confirm")->assertStatus(409);
    expect(($this->orders)()->count())->toBe(1);

    // The conversation shows the proposal as confirmed.
    $this->actingAs($this->owner)->getJson('/app/assistant/conversations/'.$done['conversation']['id'])->assertOk()
        ->assertJsonPath('turns.0.proposals.0.status', 'confirmed');
});

test('a cancelled proposal never runs, and an expired one cannot be confirmed', function () {
    $this->fake->callTool('draft_purchase_order', $this->draft)->replyWith('Please confirm.')
        ->callTool('draft_purchase_order', $this->draft)->replyWith('Please confirm.');

    $first = $this->ask($this->owner, 'Order cola')['done']['proposals'][0]['id'];
    $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$first}/cancel")->assertOk()->assertJsonPath('proposal.status', 'cancelled');
    $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$first}/confirm")->assertStatus(409);

    $second = $this->ask($this->owner, 'Order cola again')['done']['proposals'][0]['id'];
    $this->travel(16)->minutes();
    $this->actingAs($this->owner)->postJson("/app/assistant/actions/{$second}/confirm")->assertStatus(409);

    expect(($this->orders)()->count())->toBe(0)
        ->and(AiPendingAction::query()->find($second)->status)->toBe(PendingActionStatus::Expired)
        ->and(AuditLog::query()->where('action', 'ai.action_cancelled')->count())->toBe(1);
});

test('a one-shop manager is refused at the proposal, an accountant is never offered the tool', function () {
    $manager = $this->portalMember(CompanyRole::Manager, $this->leeds);
    $this->fake->callTool('draft_purchase_order', $this->draft)->replyWith('I cannot do that.');

    $done = $this->ask($manager, 'Order 2 cases of cola for Leeds')['done'];

    $result = $this->fake->toolResultsIn()[0];
    expect($result['is_error'])->toBeTrue()
        ->and($result['content'])->toBe('Only a user who can see every shop can draft head-office orders.')
        ->and($done['proposals'])->toBe([])
        ->and(AiPendingAction::query()->count())->toBe(0);

    $accountant = $this->portalMember(CompanyRole::Accountant);
    $this->fake->callTool('draft_purchase_order', $this->draft)->replyWith('Sorry.');
    $this->ask($accountant, 'Order cola');

    expect($this->fake->requests[2]->toolNames())->not->toContain('draft_purchase_order')
        ->and($this->fake->toolResultsIn()[0]['content'])->toContain('permission')
        ->and(($this->orders)()->count())->toBe(0);
});

test('another business\'s shop, supplier or product is never ordered', function () {
    $otherOwner = $this->portalMember(CompanyRole::Owner, company: $this->other);
    $this->fake->callTool('draft_purchase_order', $this->draft)->replyWith('Not found.');

    $this->ask($otherOwner, 'Order cola for Leeds');

    expect($this->fake->toolResultsIn()[0])->toMatchArray(['is_error' => true, 'content' => 'Not found in this business.'])
        ->and(AiPendingAction::query()->count())->toBe(0);
});
