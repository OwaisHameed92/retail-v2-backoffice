<?php

use App\Domain\Compliance\Actions\SaveProductRecall;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Enums\ProductRecallStatus;
use App\Domain\TillData\Models\ProductRecall;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.7: product recalls are hub-owned (ownership.json): raised and their text edited on the portal and pulled by
 * every shop's tills, schema-valid; closing, reopening, returns and the note are the tills' (ANSWERS-2026-10-06 Q3).
 * The recall page shows the stock it touches and what each shop returned.
 */

beforeEach(function () {
    $this->travelTo('2026-10-15 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->owner = C::member($this->company, CompanyRole::Owner);
    $this->pie = fn () => DB::table('products')->insert(['id' => '01K5PIE0000000000000000001', 'company_id' => $this->company->id, 'name' => 'Steak pie', 'sku' => 'PIE1']);
    $this->recalls = fn (bool $bradford = false) => collect(Pull::changes($this->sync->pull(0, bradford: $bradford)))->where('entity', 'ProductRecall')->values();
});

test('a recall raised on the portal reaches every till\'s pull, schema-valid; an edit sends its text only', function () {
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
    $schemaErrors = function (bool $bradford) use ($recall): array {
        $reply = $this->sync->pull(0, bradford: $bradford)->assertOk();
        $index = collect(Pull::changes($reply))->search(fn (array $c) => $c['entityId'] === $recall->id);
        expect($index)->not->toBeFalse();

        // Only the recall's change is checked: the product and supplier above are bare test rows.
        return [Pull::changes($reply)[$index], array_values(array_filter(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'),
            fn (string $e) => str_contains($e, "changes[{$index}]") || str_contains($e, "changes.{$index}.")))];
    };

    foreach ([false, true] as $bradford) {
        [$row, $errors] = $schemaErrors($bradford);
        expect($errors)->toBe([])->and($row['op'])->toBe('I')->and($row['payload'])->toMatchArray([
            'productId' => $pie, 'productName' => $name, 'batchCode' => 'L2231', 'expiryFrom' => '2026-10-10',
            'status' => 'open', 'raisedByUserId' => '', 'rowVersion' => 1, 'returnedQty' => 0, 'note' => '', 'closedAt' => null,
        ]);
    }

    // A till closed it (its push is stored); the portal may still correct the text, and the update leaves the till's
    // status, close, note and returned quantity out so no till's own values are overwritten.
    $recall->forceFill(['status' => ProductRecallStatus::Closed, 'closed_at' => now('UTC'), 'closed_by_user_id' => '01K5USER000000000000000001', 'note' => 'Sent back', 'returned_qty' => '5'])->save();
    $this->travel(1)->minutes();
    $this->actingAs($this->owner)->put("/app/compliance/recalls/{$recall->id}", ['product_id' => $pie, 'product_name' => $name, 'reason' => 'Undeclared mustard and celery', 'batch_code' => 'L2231', 'note' => 'ignored'])
        ->assertRedirect()->assertSessionHasNoErrors();

    [$row, $errors] = $schemaErrors(true);
    expect($errors)->toBe([])->and($row['op'])->toBe('U')
        ->and($row['payload'])->toMatchArray(['reason' => 'Undeclared mustard and celery', 'rowVersion' => 2])
        ->and($row['payload'])->not->toHaveKeys(['status', 'closedAt', 'closedByUserId', 'note', 'returnedQty']);
    expect($recall->fresh())->status->toBe(ProductRecallStatus::Closed)->note->toBe('Sent back');
    expect(AuditLog::query()->where('subject_id', $recall->id)->pluck('action')->all())->toBe(['recall.raised', 'recall.updated']);

    // Closing and reopening are the tills': the portal has no route for it.
    $this->actingAs($this->owner)->put("/app/compliance/recalls/{$recall->id}/status", ['status' => 'open'])->assertNotFound();
});

test('a recall needs a product and a reason; unknown products and suppliers are refused; a no-change save writes nothing', function () {
    $this->actingAs($this->owner)->post('/app/compliance/recalls', [])->assertSessionHasErrors(['product_name', 'reason']);
    $this->actingAs($this->owner)->post('/app/compliance/recalls', ['product_id' => '01K5NOPE000000000000000001', 'reason' => 'x'])->assertSessionHasErrors('product_id');
    $this->actingAs($this->owner)->post('/app/compliance/recalls', ['product_name' => 'Pie', 'reason' => 'x', 'supplier_id' => '01K5NOPE000000000000000001'])->assertSessionHasErrors('supplier_id');
    $this->actingAs($this->owner)->post('/app/compliance/recalls', ['product_name' => 'Pie', 'reason' => 'x', 'expiry_from' => '2026-10-20', 'expiry_to' => '2026-10-10'])->assertSessionHasErrors('expiry_to');

    $recall = app(SaveProductRecall::class)->handle($this->company, null, ['product_name' => 'Loose pies', 'reason' => 'Mould', 'reference' => 'FSA-1']);
    $again = app(SaveProductRecall::class)->handle($this->company, $recall->id, ['product_name' => 'Loose pies', 'reason' => 'Mould', 'reference' => 'FSA-1']);
    expect($again->row_version)->toBe(1)->and($recall->reference)->toBe('FSA-1');
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

    // Returns to the supplier counted per shop from the tills' stock movements against this recall, not `returnedQty`.
    $move = fn (string $id, string $branch, string $type, string $delta, string $refId) => DB::table('stock_movements')->insert([
        'id' => $id, 'company_id' => $this->company->id, 'branch_id' => $branch, 'register_id' => '', 'product_id' => '01K5PIE0000000000000000001',
        'type' => $type, 'qty_delta' => $delta, 'qty_before' => '0', 'qty_after' => '0', 'unit_cost' => '0', 'note' => '', 'ref_type' => 'Recall',
        'ref_id' => $refId, 'ref_line_id' => '', 'user_id' => '', 'at' => '2026-10-15 08:00:00', 'row_version' => 1,
    ]);
    $move('01K5SM00000000000000000001', $this->sync->leeds->id, 'supplierReturn', '-4.0000', $recall->id);
    $move('01K5SM00000000000000000002', $this->sync->leeds->id, 'supplierReturn', '-2.0000', $recall->id);
    $move('01K5SM00000000000000000003', $this->sync->leeds->id, 'wastage', '-1.0000', $recall->id);
    $move('01K5SM00000000000000000004', $this->sync->bradford->id, 'supplierReturn', '-9.0000', '01K5OTHERRECALL00000000001');
    $recall->forceFill(['returned_qty' => '99'])->save();
    // Another business's movement naming the same recall id is never counted.
    $other = new SyncApiFixtures($this, mapTillIds: false);
    DB::table('stock_movements')->insert([...(array) DB::table('stock_movements')->where('id', '01K5SM00000000000000000001')->first(),
        'id' => '01K5SM00000000000000000005', 'company_id' => $other->company->id, 'branch_id' => $other->leeds->id, 'qty_delta' => '-50.0000']);

    $p = C::props($this->actingAs($this->owner)->get("/app/compliance/recalls/{$recall->id}?shop=all"));
    expect($p['matchesBatches'])->toBeTrue()
        ->and(collect($p['stock'])->keyBy('shop')->map(fn ($s) => [$s['onHand'], $s['batchQty'], $s['batches'], $s['returned']])->all())
        ->toEqual(['Bradford' => ['4.0000', null, 0, null], 'Leeds Kirkgate' => ['10.0000', '6.0000', 1, '6.0000']])
        ->and($p['recall']['returnedQty'])->toBe('6.0000')
        ->and($p['recall'])->toMatchArray(['product' => 'Steak pie', 'status' => 'open', 'fromPortal' => true]);

    expect(C::props($this->actingAs($this->owner)->get('/app/compliance/recalls?shop=all'))['recalls']['data'][0]['onHand'])->toBe('14.0000')
        ->and(C::props($this->actingAs($this->owner)->get('/app/compliance/recalls?q=pie')->assertOk())['recalls']['meta']['total'])->toBe(1);
});
