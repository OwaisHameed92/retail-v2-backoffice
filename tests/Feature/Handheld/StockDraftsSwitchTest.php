<?php

use App\Domain\StockDrafts\Models\StockDraft;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Handheld\HandheldFixtures as H;
use Tests\Feature\Handheld\StockDraftFixtures as S;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * PDA_STOCK_DRAFTS off (the default; contract proposal pda-stock-drafts): every handheld stock call answers 501
 * NOT_AVAILABLE_YET as before, nothing is drafted, and a shop's pull is byte-identical (drafts made while it was on
 * stay out of every pull and the pull never reads the draft tables). With PDA_STOCK_DRAFTS_MIN_TILL, an older till's
 * pull keeps them waiting.
 */

beforeEach(function () {
    $this->travelTo('2026-10-22 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    H::monthlyPlan($this->company);
    F::catalogue($this->company);
    $this->headers = H::headers(H::token($this, H::user($this->company, $this->sync->leeds, ['can_adjust_stock' => true])));
    $this->adjust = ['product_id' => F::COLA, 'adjustment_type' => 'stockIncrease', 'quantity' => 4, 'unit_cost' => '0.90'];
});

test('off by default: every stock call answers 501 NOT_AVAILABLE_YET with a valid body, and 401 without a token', function () {
    expect(config('handheld.stock_drafts'))->toBeFalse();
    $order = $this->postJson('/api/pda/purchase-orders', ['supplier_id' => F::SUPPLIER, 'status' => 'sent', 'items' => [['product_id' => F::COLA, 'quantity_ordered' => 6]]], $this->headers)->json('data.id');
    $calls = [
        ['POST', '/api/pda/stock-adjustments', $this->adjust], ['GET', '/api/pda/stock-adjustments', []],
        ['PUT', "/api/pda/stock-adjustments/{$order}", $this->adjust], ['POST', "/api/pda/stock-adjustments/{$order}/cancel", []],
        ['POST', '/api/pda/stock-takes', ['name' => 'Aisle 3', 'lines' => [['product_id' => F::COLA, 'counted_quantity' => 3]]]], ['GET', '/api/pda/stock-takes', []],
        ['GET', "/api/pda/stock-takes/{$order}", []], ['PUT', "/api/pda/stock-takes/{$order}", []], ['POST', "/api/pda/stock-takes/{$order}/cancel", []],
        ['POST', "/api/pda/purchase-orders/{$order}/receive", []], ['GET', "/api/pda/purchase-orders/{$order}/receipts", []],
        ['GET', "/api/pda/purchase-orders/{$order}/returnable", []], ['GET', '/api/pda/purchase-returns', []],
        ['POST', '/api/pda/purchase-returns', ['supplier_id' => F::SUPPLIER, 'items' => [['product_id' => F::COLA, 'quantity' => 1]]]],
        ['PUT', "/api/pda/purchase-returns/{$order}", []], ['POST', "/api/pda/purchase-returns/{$order}/cancel", []],
    ];

    foreach ($calls as [$method, $url, $body]) {
        $this->json($method, $url, $body, $this->headers)->assertStatus(501)->assertJson(['success' => false, 'code' => 'NOT_AVAILABLE_YET'])
            ->assertJsonPath('message', 'This is done on the shop’s till for now. It comes to the handheld app in phase 2.');
        $this->json($method, $url, $body)->assertUnauthorized();
    }

    expect(StockDraft::withoutCompanyScope()->count())->toBe(0);
    $this->getJson("/api/pda/purchase-orders/{$order}", $this->headers)->assertJsonPath('data.total_returned', null)
        ->assertJsonPath('data.items.0.quantity_returned', null)->assertJsonMissingPath('data.items.0.quantity_pending');
});

test('off: the pull is byte-identical and never reads the draft tables, even with drafts made while it was on', function () {
    $before = $this->sync->pull(0)->getContent();
    $beforeBradford = $this->sync->pull(0, bradford: true)->getContent();

    S::on();
    $this->postJson('/api/pda/stock-adjustments', $this->adjust, $this->headers)->assertCreated();
    $this->postJson('/api/pda/stock-takes', ['name' => 'Aisle 3', 'lines' => [['product_id' => F::COLA, 'counted_quantity' => 3]]], $this->headers)->assertCreated();
    config(['handheld.stock_drafts' => false]);

    $read = false;
    DB::listen(function (QueryExecuted $query) use (&$read) {
        $read = $read || preg_match('/[`"]stock_draft/', $query->sql) === 1;
    });

    expect($this->sync->pull(0)->getContent())->toBe($before)
        ->and($this->sync->pull(0, bradford: true)->getContent())->toBe($beforeBradford)
        ->and($read)->toBeFalse()
        ->and(StockDraft::withoutCompanyScope()->pluck('state')->map->value->all())->toBe(['pending', 'pending']);
});

test('on with a minimum till version: an older till does not get the drafts, a new enough one does', function () {
    S::on();
    config(['handheld.stock_drafts_min_till' => '3.1.0']);   // the fixtures' till reports 3.0.412
    $since = $this->sync->pull(0)->json('highestVersion');
    $this->postJson('/api/pda/stock-adjustments', $this->adjust, $this->headers)->assertCreated();

    expect(S::drafted($this->sync->pull($since)))->toBe([])
        ->and(S::drafted($this->sync->pull($since, headers: ['X-SSPOS-App-Version' => '3.1.0'])))->toHaveCount(1);
});
