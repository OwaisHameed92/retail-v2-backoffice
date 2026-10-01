<?php

use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Labels\Models\LabelTemplate;
use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;

uses(TenancyTestHelpers::class);

/** Gap #6: the shelf-label screen: access, one-shop rule, isolation, manual queue, preview, PDF and templates. */
beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->ids = CatalogueFixtures::seed($this->company);
    $this->product = PricingFixtures::product($this->company, $this->ids, ['barcodes' => [['id' => null, 'barcode' => '5010044000701', 'pack_qty' => 1, 'is_primary' => true]]]);
    $this->as = fn (Closure $fn) => app(CurrentCompany::class)->runAs($this->company, $fn);
    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['sell_price' => '1.60']));
    $this->leedsItem = LabelQueueItem::withoutCompanyScope()->where('branch_id', $this->sync->leeds->id)->sole();
    $this->bradfordItem = LabelQueueItem::withoutCompanyScope()->where('branch_id', $this->sync->bradford->id)->sole();
    $this->manager = $this->memberOf($this->company, CompanyRole::Manager);
    $this->oneShop = $this->memberOf($this->company, CompanyRole::Manager);
    DB::table('company_user')->where('user_id', $this->oneShop->id)->update(['branch_id' => $this->sync->leeds->id]);
});

/** @return list<array{0: string, 1: string, 2: array<string, mixed>}> */
function labelWrites(object $t, string $shop, string $item): array
{
    return [
        ['post', '/app/labels/queue', ['branch_id' => $shop, 'product_ids' => [$t->product->id]]],
        ['post', '/app/labels/printed', ['branch_id' => $shop, 'ids' => [$item]]],
        ['post', '/app/labels/remove', ['branch_id' => $shop, 'ids' => [$item]]],
        ['post', '/app/labels/copies', ['branch_id' => $shop, 'ids' => [$item], 'copies' => 3]],
        ['post', '/app/labels/preview', ['branch_id' => $shop, 'ids' => [$item]]],
        ['post', '/app/labels/pdf', ['branch_id' => $shop, 'ids' => [$item]]],
        ['post', '/app/labels/templates', ['name' => 'Big', 'stock' => 'a4_2x4', 'branch_id' => $shop]],
    ];
}

test('guests are sent to the login page; accountants and staff (read only) get 403 everywhere', function () {
    $this->get('/app/labels')->assertRedirect('/login');
    foreach (labelWrites($this, $this->sync->leeds->id, $this->leedsItem->id) as [$method, $url, $data]) {
        $this->{$method}($url, $data)->assertRedirect('/login');
    }

    foreach ([CompanyRole::Accountant, CompanyRole::Staff] as $role) {
        $user = $this->memberOf($this->company, $role);
        $this->actingAs($user)->get('/app/labels')->assertForbidden();
        $this->actingAs($user)->get('/app/labels/products?branch_id='.$this->sync->leeds->id.'&q=toast')->assertForbidden();
        foreach (labelWrites($this, $this->sync->leeds->id, $this->leedsItem->id) as [$method, $url, $data]) {
            $this->actingAs($user)->{$method}($url, $data)->assertForbidden();
        }
    }

    expect($this->leedsItem->fresh()->pending)->toBeTrue()->and($this->leedsItem->fresh()->copies)->toBe(1)->and(LabelTemplate::withoutCompanyScope()->count())->toBe(0);
});

test('the queue lists one shop with counts, search and the printed view', function () {
    $this->actingAs($this->manager)->get('/app/labels?shop='.$this->sync->bradford->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->component('app/labels/index')->where('shop.id', $this->sync->bradford->id)->has('shops', 2)->has('items.data', 1)
        ->where('items.data.0.id', $this->bradfordItem->id)->where('items.data.0.price', '1.60')->where('items.data.0.reason', 'priceChange')
        ->where('counts.waiting', 1)->where('counts.priceChanges', 1)->has('templates', 1)->where('templates.0.id', 'builtin'));

    $this->actingAs($this->manager)->get('/app/labels?shop='.$this->sync->leeds->id.'&search=nothing-like-it')->assertInertia(fn (AssertableInertia $p) => $p->has('items.data', 0));
    $this->actingAs($this->manager)->get('/app/labels?shop='.$this->sync->leeds->id.'&search=5010044000701')->assertInertia(fn (AssertableInertia $p) => $p->has('items.data', 1));
    $this->actingAs($this->manager)->get('/app/labels?shop='.$this->sync->leeds->id.'&view=printed')->assertInertia(fn (AssertableInertia $p) => $p->has('items.data', 0));
});

test('adding by hand: products (found by name or barcode), a department or a supplier; deduped', function () {
    $this->actingAs($this->manager)->getJson('/app/labels/products?branch_id='.$this->sync->leeds->id.'&q=5010044000701')->assertOk()
        ->assertJsonPath('products.0.id', $this->product->id)->assertJsonPath('products.0.waiting', true);

    $other = PricingFixtures::product($this->company, $this->ids, ['name' => 'Seeded Batch 400g', 'sku' => 'SEED-400', 'barcodes' => []]);
    $this->actingAs($this->manager)->post('/app/labels/queue', ['branch_id' => $this->sync->leeds->id, 'product_ids' => [$this->product->id, $other->id]])
        ->assertSessionHas('success', '2 labels added to the queue.');
    expect(LabelQueueItem::withoutCompanyScope()->where('branch_id', $this->sync->leeds->id)->count())->toBe(2)
        ->and($this->leedsItem->fresh()->times_queued)->toBe(2)->and($this->leedsItem->fresh()->reason->value)->toBe('manual');

    $this->actingAs($this->manager)->post('/app/labels/queue', ['branch_id' => $this->sync->bradford->id, 'department_id' => $this->ids['department']])->assertSessionHas('success', '2 labels added to the queue.');

    $supplier = Ulid::new();
    Pull::portalCreate($this->company, 'Supplier', Pull::payload('Supplier', $supplier, ['companyId' => $this->company->id, 'name' => 'Booker', 'code' => 'BKR', 'isActive' => true]));
    $this->actingAs($this->manager)->post('/app/labels/queue', ['branch_id' => $this->sync->bradford->id, 'supplier_id' => $supplier])
        ->assertSessionHasErrors(['supplier_id' => 'No products on sale match that choice.']);
    $this->actingAs($this->manager)->post('/app/labels/queue', ['branch_id' => $this->sync->bradford->id])->assertSessionHasErrors('product_ids');
});

test('preview shows what the label says; the PDF renders on A4 and roll stocks and can mark the labels printed', function () {
    ($this->as)(fn () => app(SavePromotion::class)->handle(null, Arr::except(PricingFixtures::offer($this->product->id, [
        'name' => 'Toast deal', 'type' => 'multiBuy', 'percent' => null, 'buy_quantity' => '3', 'deal_price' => '4.00', 'effective_to' => '2026-10-20',
    ]), ['items'])));

    $this->actingAs($this->manager)->postJson('/app/labels/preview', ['branch_id' => $this->sync->leeds->id, 'ids' => [$this->leedsItem->id]])->assertOk()
        ->assertJsonPath('labels.0.name', 'Toastie White 800g')->assertJsonPath('labels.0.priceText', '£1.60')
        ->assertJsonPath('labels.0.unitPrice', '20p per 100g')->assertJsonPath('labels.0.offer', '3 for £4.00')
        ->assertJsonPath('labels.0.offerUntil', 'Ends 20 Oct')->assertJsonPath('labels.0.barcode.type', 'ean13')
        ->assertJsonPath('labels.0.shop', 'Leeds Kirkgate')->assertJsonPath('labels.0.date', '05/10/26')
        ->assertJsonPath('stock.key', 'a4_3x8')->assertJsonPath('pages', 1);

    $template = ($this->as)(fn () => LabelTemplate::query()->create(['name' => 'Roll', 'stock' => 'roll_50x30', 'branch_id' => $this->sync->leeds->id, 'options' => [], 'is_default' => true]));
    $this->actingAs($this->manager)->postJson('/app/labels/copies', ['branch_id' => $this->sync->leeds->id, 'ids' => [$this->leedsItem->id], 'copies' => 3])->assertRedirect();
    $this->actingAs($this->manager)->postJson('/app/labels/preview', ['branch_id' => $this->sync->leeds->id, 'all' => true])
        ->assertJsonPath('template.id', $template->id)->assertJsonPath('count', 3)->assertJsonPath('pages', 3);

    foreach (['builtin', $template->id] as $templateId) {
        $response = $this->actingAs($this->manager)->post('/app/labels/pdf', ['branch_id' => $this->sync->leeds->id, 'ids' => [$this->leedsItem->id], 'template_id' => $templateId, 'skip' => 4]);
        $response->assertOk()->assertHeader('Content-Type', 'application/pdf');
        expect(substr((string) $response->getContent(), 0, 5))->toBe('%PDF-')
            ->and($response->headers->get('Content-Disposition'))->toContain('shelf-labels-leeds-kirkgate-2026-10-05');
    }
    expect($this->leedsItem->fresh()->pending)->toBeTrue();

    $this->actingAs($this->manager)->post('/app/labels/pdf', ['branch_id' => $this->sync->leeds->id, 'all' => true, 'mark_printed' => true])->assertOk();
    expect($this->leedsItem->fresh()->pending)->toBeFalse()->and($this->leedsItem->fresh()->printed_by_user_id)->toBe($this->manager->id)
        ->and($this->bradfordItem->fresh()->pending)->toBeTrue();
});

test('mark printed, take off and copies change only that shop\'s waiting labels', function () {
    $this->actingAs($this->manager)->post('/app/labels/remove', ['branch_id' => $this->sync->leeds->id, 'ids' => [$this->leedsItem->id, $this->bradfordItem->id]])
        ->assertSessionHas('success', '1 label taken off the queue.');
    expect($this->leedsItem->fresh()->pending)->toBeFalse()->and($this->leedsItem->fresh()->printed_at)->toBeNull()->and($this->bradfordItem->fresh()->pending)->toBeTrue();

    $this->actingAs($this->manager)->post('/app/labels/printed', ['branch_id' => $this->sync->leeds->id, 'ids' => [$this->leedsItem->id]])->assertSessionHasErrors('ids');
    $this->actingAs($this->manager)->post('/app/labels/printed', ['branch_id' => $this->sync->bradford->id, 'all' => true])->assertSessionHas('success', '1 label marked as printed.');
    $this->actingAs($this->manager)->get('/app/labels?shop='.$this->sync->bradford->id.'&view=printed')->assertInertia(fn (AssertableInertia $p) => $p->has('items.data', 1)->where('counts.printedWeek', 1));
});

test('a one-shop user sees and works only on their own shop and its templates', function () {
    $this->actingAs($this->oneShop)->get('/app/labels?shop='.$this->sync->bradford->id)->assertOk()->assertInertia(fn (AssertableInertia $p) => $p
        ->where('shop.id', $this->sync->leeds->id)->has('shops', 1)->where('restrictedShop', $this->sync->leeds->id));

    foreach (labelWrites($this, $this->sync->bradford->id, $this->bradfordItem->id) as [$method, $url, $data]) {
        $this->actingAs($this->oneShop)->{$method}($url, $data)->assertForbidden();
    }
    $this->actingAs($this->oneShop)->get('/app/labels/products?branch_id='.$this->sync->bradford->id.'&q=toast')->assertForbidden();
    $this->actingAs($this->oneShop)->post('/app/labels/templates', ['name' => 'Every shop', 'stock' => 'a4_3x7', 'branch_id' => null])->assertForbidden();
    $everyShop = ($this->as)(fn () => LabelTemplate::query()->create(['name' => 'Shared', 'stock' => 'a4_3x7', 'branch_id' => null, 'options' => [], 'is_default' => false]));
    $this->actingAs($this->oneShop)->delete("/app/labels/templates/{$everyShop->id}")->assertForbidden();

    $this->actingAs($this->oneShop)->post('/app/labels/templates', ['name' => 'Leeds big', 'stock' => 'a4_2x4', 'branch_id' => $this->sync->leeds->id, 'is_default' => true,
        'options' => ['show_barcode' => false]])->assertSessionHas('success');
    $mine = LabelTemplate::withoutCompanyScope()->where('name', 'Leeds big')->sole();
    expect($mine->options['show_barcode'])->toBeFalse()->and($mine->options['show_unit_price'])->toBeTrue()->and($mine->is_default)->toBeTrue();

    $this->actingAs($this->oneShop)->put("/app/labels/templates/{$mine->id}", ['name' => 'Leeds 2x7', 'stock' => 'a4_2x7', 'branch_id' => $this->sync->leeds->id])->assertSessionHas('success');
    $this->actingAs($this->oneShop)->post('/app/labels/printed', ['branch_id' => $this->sync->leeds->id, 'ids' => [$this->leedsItem->id]])->assertSessionHas('success');
    $this->actingAs($this->oneShop)->delete("/app/labels/templates/{$mine->id}")->assertSessionHas('success');

    expect($this->bradfordItem->fresh()->pending)->toBeTrue()->and(LabelTemplate::withoutCompanyScope()->pluck('name')->all())->toBe(['Shared']);
});

test('templates: one default per shop, an unknown stock is refused', function () {
    $this->actingAs($this->manager)->post('/app/labels/templates', ['name' => 'A', 'stock' => 'a4_3x8', 'branch_id' => null, 'is_default' => true])->assertSessionHas('success');
    $this->actingAs($this->manager)->post('/app/labels/templates', ['name' => 'B', 'stock' => 'a4_4x10', 'branch_id' => null, 'is_default' => true])->assertSessionHas('success');
    $this->actingAs($this->manager)->post('/app/labels/templates', ['name' => 'C', 'stock' => 'a5_huge'])->assertSessionHasErrors(['stock' => 'Choose a label stock.']);

    expect(LabelTemplate::withoutCompanyScope()->orderBy('name')->pluck('is_default', 'name')->all())->toBe(['A' => false, 'B' => true]);
    $this->actingAs($this->manager)->get('/app/labels')->assertInertia(fn (AssertableInertia $p) => $p->where('templates.0.name', 'B')->has('templates', 3));
});

test('another business cannot see or change this business\'s labels, shops or templates', function () {
    $other = Company::factory()->create();
    $stranger = $this->memberOf($other, CompanyRole::Owner);
    $theirShop = Branch::factory()->forCompany($other)->create(['name' => 'Elsewhere']);
    $template = ($this->as)(fn () => LabelTemplate::query()->create(['name' => 'Ours', 'stock' => 'a4_3x7', 'branch_id' => null, 'options' => [], 'is_default' => false]));

    foreach (labelWrites($this, $this->sync->leeds->id, $this->leedsItem->id) as [$method, $url, $data]) {
        $status = $this->actingAs($stranger)->{$method}($url, $data)->getStatusCode();
        expect($status)->toBeIn([404, 422, 302]);
    }
    $this->actingAs($stranger)->post('/app/labels/printed', ['branch_id' => $theirShop->id, 'ids' => [$this->leedsItem->id]])->assertSessionHasErrors('ids');
    $this->actingAs($stranger)->post('/app/labels/pdf', ['branch_id' => $theirShop->id, 'ids' => [$this->leedsItem->id]])->assertSessionHasErrors('ids');
    $this->actingAs($stranger)->put("/app/labels/templates/{$template->id}", ['name' => 'Theirs', 'stock' => 'a4_3x7'])->assertNotFound();
    $this->actingAs($stranger)->delete("/app/labels/templates/{$template->id}")->assertNotFound();
    $this->actingAs($stranger)->get('/app/labels')->assertInertia(fn (AssertableInertia $p) => $p->where('shop.id', $theirShop->id)->has('items.data', 0)->where('counts.waiting', 0));
    $this->actingAs($stranger)->getJson('/app/labels/products?branch_id='.$theirShop->id.'&q=Toastie')->assertJsonCount(0, 'products');

    expect($this->leedsItem->fresh()->pending)->toBeTrue()->and($this->leedsItem->fresh()->copies)->toBe(1)
        ->and(LabelQueueItem::withoutCompanyScope()->count())->toBe(2)->and($template->fresh()->name)->toBe('Ours')
        ->and(LabelTemplate::withoutCompanyScope()->count())->toBe(1);
});
