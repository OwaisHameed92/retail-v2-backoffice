<?php

use App\Domain\Compliance\Actions\ChangeRecallStatus;
use App\Domain\Compliance\Actions\SaveProductRecall;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\ProductRecall;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.7: product recalls are hub-owned (ownership.json): raised, edited and closed on the portal and pulled by
 * every shop's tills, schema-valid; the recall page shows the stock it touches in each shop.
 */

beforeEach(function () {
    $this->travelTo('2026-10-15 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->pie = fn () => DB::table('products')->insert(['id' => '01K5PIE0000000000000000001', 'company_id' => $this->company->id, 'name' => 'Steak pie', 'sku' => 'PIE1']);
    $this->recalls = fn (bool $bradford = false) => collect(Pull::changes($this->sync->pull(0, bradford: $bradford)))->where('entity', 'ProductRecall')->values();
});

test('a recall raised on the portal reaches every till\'s pull, schema-valid, and edits and closing follow', function () {
    // A catalogue product the Leeds till made (the contract sample), so every pulled row is a real one.
    $product = TillFixtures::sample('entities/Product.json');
    $this->sync->push([TillFixtures::envelope('Product', $product, 1)])->assertOk();
    $pie = $product['id'];
    $name = $product['name'];

    $this->actingAs($this->owner)->post('/app/compliance/recalls', [
        'product_id' => $pie, 'batch_code' => 'L2231', 'expiry_from' => '2026-10-10', 'expiry_to' => '2026-10-20',
        'source' => 'Food Standards Agency', 'reason' => 'Undeclared mustard',
    ])->assertRedirect()->assertSessionHas('success');

    $recall = ProductRecall::query()->withoutGlobalScopes()->where('company_id', $this->company->id)->sole();
    expect($recall->reference)->toStartWith('RC-261015-')->and($recall->product_name)->toBe($name)->and($recall->status)->toBe(ProductRecallStatus::Open);

    foreach ([false, true] as $bradford) {
        $reply = $this->sync->pull(0, bradford: $bradford)->assertOk();
        $index = collect(Pull::changes($reply))->search(fn (array $c) => $c['entityId'] === $recall->id);
        // Only the recall's change is checked: the product and supplier above are bare test rows.
        $errors = array_filter(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'), fn (string $e) => str_contains($e, "changes[{$index}]") || str_contains($e, "changes.{$index}."));
        expect($index)->not->toBeFalse()->and($errors)->toBe([]);
        $row = Pull::changes($reply)[$index];
        expect($row['op'])->toBe('I')->and($row['payload'])->toMatchArray([
            'productId' => $pie, 'productName' => $name, 'batchCode' => 'L2231', 'expiryFrom' => '2026-10-10',
            'status' => 'open', 'raisedByUserId' => '', 'rowVersion' => 1,
        ]);
    }

    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put("/app/compliance/recalls/{$recall->id}", ['product_id' => $pie, 'product_name' => $name, 'reason' => 'Undeclared mustard and celery', 'batch_code' => 'L2231'])
        ->assertRedirect();
    expect(($this->recalls)(true)->firstWhere('entityId', $recall->id)['payload'])->toMatchArray(['reason' => 'Undeclared mustard and celery', 'rowVersion' => 2]);

    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put("/app/compliance/recalls/{$recall->id}/status", ['status' => 'closed', 'returned_qty' => '12'])->assertRedirect();
    expect(($this->recalls)()->firstWhere('entityId', $recall->id)['payload'])->toMatchArray(['status' => 'closed', 'returnedQty' => 12, 'rowVersion' => 3]);
    expect(AuditLog::query()->where('subject_id', $recall->id)->pluck('action')->all())->toBe(['recall.raised', 'recall.updated', 'recall.closed']);

    // Closed: no edits until reopened.
    $this->actingAs($this->owner)->put("/app/compliance/recalls/{$recall->id}", ['product_name' => 'X', 'reason' => 'Y'])->assertSessionHasErrors('reason');
    $this->actingAs($this->owner)->put("/app/compliance/recalls/{$recall->id}/status", ['status' => 'open'])->assertRedirect();
    expect($recall->fresh()->status)->toBe(ProductRecallStatus::Open)->and($recall->fresh()->closed_at)->toBeNull();
});

test('a recall needs a product and a reason; unknown products and suppliers are refused; a no-change save writes nothing', function () {
    $this->actingAs($this->owner)->post('/app/compliance/recalls', [])->assertSessionHasErrors(['product_name', 'reason']);
    $this->actingAs($this->owner)->post('/app/compliance/recalls', ['product_id' => '01K5NOPE000000000000000001', 'reason' => 'x'])->assertSessionHasErrors('product_id');
    $this->actingAs($this->owner)->post('/app/compliance/recalls', ['product_name' => 'Pie', 'reason' => 'x', 'supplier_id' => '01K5NOPE000000000000000001'])->assertSessionHasErrors('supplier_id');
    $this->actingAs($this->owner)->post('/app/compliance/recalls', ['product_name' => 'Pie', 'reason' => 'x', 'expiry_from' => '2026-10-20', 'expiry_to' => '2026-10-10'])->assertSessionHasErrors('expiry_to');

    $recall = app(SaveProductRecall::class)->handle($this->company, null, ['product_name' => 'Loose pies', 'reason' => 'Mould', 'reference' => 'FSA-1']);
    $again = app(SaveProductRecall::class)->handle($this->company, $recall->id, ['product_name' => 'Loose pies', 'reason' => 'Mould', 'reference' => 'FSA-1']);
    expect($again->row_version)->toBe(1)->and($recall->reference)->toBe('FSA-1');

    expect(fn () => app(ChangeRecallStatus::class)->handle($this->company, $recall->id, ProductRecallStatus::Open))->toThrow(ValidationException::class);
});

test('the recall page shows on hand and matching batches per shop', function () {
    ($this->pie)();
    $recall = app(SaveProductRecall::class)->handle($this->company, null, ['product_id' => '01K5PIE0000000000000000001', 'batch_code' => 'L2231', 'reason' => 'Mould']);
    $bp = fn (string $id, string $branch, string $qty) => DB::table('branch_products')->insert(['id' => $id, 'company_id' => $this->company->id, 'branch_id' => $branch, 'product_id' => '01K5PIE0000000000000000001', 'qty_on_hand' => $qty]);
    $bp('01K5BP00000000000000000001', $this->sync->leeds->id, '10.0000');
    $bp('01K5BP00000000000000000002', $this->sync->bradford->id, '4.0000');
    $layer = fn (string $id, string $branch, string $batch, string $qty) => DB::table('stock_layers')->insert(['id' => $id, 'company_id' => $this->company->id, 'branch_id' => $branch, 'product_id' => '01K5PIE0000000000000000001', 'batch_no' => $batch, 'qty_remaining' => $qty, 'received_at' => '2026-10-01 08:00:00']);
    $layer('01K5SL00000000000000000001', $this->sync->leeds->id, 'L2231', '6.0000');
    $layer('01K5SL00000000000000000002', $this->sync->leeds->id, 'L9999', '4.0000');

    $p = C::props($this->actingAs($this->owner)->get("/app/compliance/recalls/{$recall->id}?shop=all"));
    expect($p['matchesBatches'])->toBeTrue()
        ->and(collect($p['stock'])->keyBy('shop')->map(fn ($s) => [$s['onHand'], $s['batchQty'], $s['batches']])->all())
        ->toEqual(['Bradford' => ['4.0000', null, 0], 'Leeds Kirkgate' => ['10.0000', '6.0000', 1]])
        ->and($p['recall'])->toMatchArray(['product' => 'Steak pie', 'status' => 'open', 'fromPortal' => true]);

    expect(C::props($this->actingAs($this->owner)->get('/app/compliance/recalls?shop=all'))['recalls']['data'][0]['onHand'])->toBe('14.0000')
        ->and(C::props($this->actingAs($this->owner)->get('/app/compliance/recalls?q=pie')->assertOk())['recalls']['meta']['total'])->toBe(1);
});
