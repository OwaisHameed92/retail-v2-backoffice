<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\PurchaseOrder;
use App\Domain\TillData\Models\PurchaseOrderLine;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\PortalAssistantHelpers;
use Tests\Feature\Purchasing\PurchasingFixtures as F;
use Tests\Feature\Purchasing\ReorderFixtures as R;

/*
 * Module 6.4: the reorder suggestions screen (/app/purchasing/suggestions) on the standard scenario (ReorderFixtures),
 * drafting orders from it, the optional AI note (FakeAiClient), permissions and isolation. "Today" is Wed 23 Sept 2026.
 */

uses(PortalAssistantHelpers::class);

beforeEach(function () {
    $this->setUpPortal();
    F::catalogue($this->kirkgate);
    R::scenario($this->kirkgate, $this->leeds, $this->bradford);
    $this->orders = fn () => PurchaseOrder::withoutCompanyScope()->where('company_id', $this->kirkgate->id)->where('origin', 'headOffice');
    $this->props = fn (string $query = '', $user = null) => $this->actingAs($user ?? $this->owner)->get('/app/purchasing/suggestions'.$query)
        ->assertOk()->viewData('page')['props'];
    $this->line = fn (array $props, string $shopId, string $product = F::COLA) => collect($props['lines'])->first(fn (array $l) => $l['shopId'] === $shopId && $l['productId'] === $product);
});

test('the owner sees what each shop should order, grouped by shop and supplier, with the reasons', function () {
    $this->actingAs($this->owner)->get('/app/purchasing/suggestions')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/purchasing/suggestions')->has('lines', 2)->has('groups', 2)
            ->where('stats', ['lines' => 2, 'orders' => 2, 'cost' => '48.00', 'attention' => 1])->where('can.manage', true)->where('ai.available', true)
            ->where('lines.0.shopName', 'Bradford')->where('lines.1.shopName', 'Leeds')
            ->has('notable', 1)->where('notable.0.shopName', 'Bradford'));

    $leeds = ($this->line)(($this->props)(), $this->leeds->id);
    expect($leeds)->toMatchArray([
        'suggestedCases' => 2, 'suggestedUnits' => '48.0000', 'suggestedCost' => '24.00', 'caseQty' => 24, 'unitCost' => '0.5000',
        'onHand' => '10.0000', 'onOrder' => '24.0000', 'inTransit' => '0.0000', 'minLevel' => '12.0000', 'rate' => '6.0000',
        'coverDays' => '1.7', 'forecast' => '60.0000', 'horizonDays' => 10, 'method' => 'forecast',
    ])->and($leeds['flags'])->toBe([])
        ->and($leeds['reasons'])->toContain('This order arrives in 3 days and has to last 10 days, until the next one arrives.')
        ->and($leeds['reasons'])->toContain('Needs 60 + 12 spare − 34 in stock and on the way = 38.')
        ->and($leeds['reasons'])->toContain('Order 2 cases of 24 = 48 units, rounded up to whole cases.');

    $groups = collect(($this->props)()['groups'])->keyBy('shopName');
    expect($groups['Leeds'])->toMatchArray(['leadDays' => 3, 'leadBasis' => 'shop', 'leadSamples' => 2, 'reviewDays' => 7])
        ->and($groups['Bradford'])->toMatchArray(['leadDays' => 3, 'leadBasis' => 'business']);
});

test('filters: shop, supplier, department, search and the attention and everything views', function () {
    expect(($this->props)('?shop='.$this->leeds->id)['lines'])->toHaveCount(1)
        ->and(($this->props)('?supplier=01K5T0Q8C4000000000000S999')['lines'])->toBe([])
        ->and(($this->props)('?q=bread')['lines'])->toBe([])
        ->and(($this->props)('?q=bread&view=all')['lines'])->toHaveCount(1)
        ->and(($this->props)('?view=all')['lines'])->toHaveCount(3)
        ->and(($this->props)('?department=nope&view=all')['lines'])->toBe([]);

    // Worth a look: Bradford runs out first. Leeds's bread is overstocked (shown on the line, not a reordering question).
    $attention = ($this->props)('?view=attention')['lines'];
    $water = ($this->line)(($this->props)('?view=all'), $this->leeds->id, F::WATER);
    expect(array_column($attention, 'shopName'))->toBe(['Bradford'])
        ->and($water['suggestedCases'])->toBe(0)->and($water['flags'])->toBe(['overstock']);
});

test('transfers on the way are taken off, and so is a draft made from the suggestions', function () {
    R::transfer($this->kirkgate, $this->bradford, $this->leeds, F::COLA, '24');
    R::transfer($this->kirkgate, $this->bradford, $this->leeds, F::COLA, '24', status: 'received');

    $leeds = ($this->line)(($this->props)(), $this->leeds->id);
    expect($leeds['inTransit'])->toBe('24.0000')->and($leeds['suggestedCases'])->toBe(1);   // 60 + 12 − 58 = 14 → 1 case
});

test('a short-life product is not ordered beyond what sells before its date', function () {
    R::batch($this->kirkgate, $this->leeds, F::COLA, '2026-09-10 07:00:00', '2026-09-14');   // 4 days

    $props = ($this->props)('?view=all');
    $leeds = ($this->line)($props, $this->leeds->id);
    $bradford = ($this->line)($props, $this->bradford->id);

    // Leeds: arriving in 3 days it can sell 24 in 4 days, but 16 of today's stock is still there: 8 → less than a case.
    expect($leeds['shelfLifeDays'])->toBe(4)->and($leeds['suggestedCases'])->toBe(0)->and($leeds['flags'])->toContain('shortLife')
        // Bradford runs out before the delivery: one case, flagged.
        ->and($bradford['suggestedCases'])->toBe(1)->and($bradford['flags'])->toContain('wasteRisk');
});

test('a seasonal event uses last year\'s uplift for the product', function () {
    R::event($this->kirkgate, $this->leeds, 'Halloween', '2026-09-25', '2026-09-27');
    // Last year (same dates, no event of that name): 6 a day for the 28 days before, 18 a day on the 3 event days.
    R::sales($this->kirkgate, $this->leeds, F::COLA, '2025-09-25', fn () => 6, weeks: 4);
    R::sales($this->kirkgate, $this->leeds, F::COLA, '2025-09-28', fn (CarbonImmutable $d) => $d->toDateString() >= '2025-09-25' ? 18 : 0, weeks: 1);

    $leeds = ($this->line)(($this->props)(), $this->leeds->id);

    // 7 normal days × 6 + 3 event days × 18 = 96; 96 + 12 − 34 = 74 → 4 cases.
    expect($leeds['events'])->toBe(['Halloween'])->and($leeds['forecast'])->toBe('96.0000')->and($leeds['suggestedCases'])->toBe(4)
        ->and($leeds['flags'])->toContain('seasonal')
        ->and(implode(' ', $leeds['reasons']))->toContain('Halloween ×3 from last year\'s sales');
});

test('creating the orders drafts one head-office order per shop and supplier, with the edited cases', function () {
    $lines = [
        ['shopId' => $this->leeds->id, 'supplierId' => F::SUPPLIER, 'productId' => F::COLA, 'cases' => 3],
        ['shopId' => $this->bradford->id, 'supplierId' => F::SUPPLIER, 'productId' => F::COLA, 'cases' => 2],
        ['shopId' => $this->leeds->id, 'supplierId' => F::SUPPLIER, 'productId' => F::WATER, 'cases' => 0],
    ];

    $this->actingAs($this->owner)->post('/app/purchasing/suggestions/orders', ['lines' => $lines])
        ->assertRedirect('/app/purchasing/orders?origin=headOffice&status=draft')->assertSessionHas('success');

    $orders = ($this->orders)()->get()->keyBy('branch_id');
    $leedsLines = PurchaseOrderLine::withoutCompanyScope()->where('purchase_order_id', $orders[$this->leeds->id]->id)->get();

    expect($orders)->toHaveCount(2)
        ->and($orders[$this->leeds->id]->status?->value)->toBe('draft')
        ->and($orders[$this->leeds->id]->notes)->toBe('From reorder suggestions.')
        ->and($leedsLines)->toHaveCount(1)
        ->and([(int) $leedsLines[0]->ordered_cases, (int) $leedsLines[0]->case_qty_snapshot, $leedsLines[0]->ordered_units, $leedsLines[0]->unit_cost_snapshot])
        ->toBe([3, 24, '72.0000', '0.5000'])
        ->and(AuditLog::query()->where('action', 'purchase_order.head_office_drafted')->count())->toBe(2);

    // The drafts count as open orders: Leeds needs nothing more, Bradford neither.
    expect(($this->props)()['lines'])->toBe([]);
});

test('creating orders is refused without lines, for a closed shop and for another business\'s ids', function () {
    $this->actingAs($this->owner)->post('/app/purchasing/suggestions/orders', ['lines' => []])->assertSessionHasErrors('lines');
    $this->actingAs($this->owner)->post('/app/purchasing/suggestions/orders', ['lines' => [
        ['shopId' => $this->leeds->id, 'supplierId' => F::SUPPLIER, 'productId' => F::COLA, 'cases' => 0],
    ]])->assertSessionHasErrors(['lines' => 'Choose at least one product with a quantity to order.']);

    $this->bradford->forceFill(['is_active' => false])->save();
    $this->actingAs($this->owner)->post('/app/purchasing/suggestions/orders', ['lines' => [
        ['shopId' => $this->bradford->id, 'supplierId' => F::SUPPLIER, 'productId' => F::COLA, 'cases' => 1],
    ]])->assertSessionHasErrors(['lines' => 'Bradford is closed. Leave it out of the order.']);

    $otherOwner = $this->portalMember(CompanyRole::Owner, company: $this->other);
    $this->actingAs($otherOwner)->post('/app/purchasing/suggestions/orders', ['lines' => [
        ['shopId' => $this->leeds->id, 'supplierId' => F::SUPPLIER, 'productId' => F::COLA, 'cases' => 1],
    ]])->assertSessionHasErrors(['lines' => 'A shop on the order was not found in this business.']);

    expect(PurchaseOrder::withoutCompanyScope()->where('origin', 'headOffice')->count())->toBe(0);
});

test('another business sees none of these suggestions', function () {
    $props = ($this->props)('?shop='.$this->leeds->id, $this->portalMember(CompanyRole::Owner, company: $this->other));

    expect($props['lines'])->toBe([])->and(collect($props['shops'])->pluck('id')->all())->toBe([$this->otherShop->id]);
});

test('a one-shop manager sees only their shop and cannot create orders; an accountant can look; staff cannot', function () {
    $manager = $this->portalMember(CompanyRole::Manager, $this->leeds);
    $props = ($this->props)('?shop='.$this->bradford->id, $manager);

    expect(collect($props['lines'])->pluck('shopId')->unique()->all())->toBe([$this->leeds->id])
        ->and($props['can']['manage'])->toBeFalse()->and($props['oneShop'])->toBeTrue();
    $this->actingAs($manager)->post('/app/purchasing/suggestions/orders', ['lines' => [
        ['shopId' => $this->leeds->id, 'supplierId' => F::SUPPLIER, 'productId' => F::COLA, 'cases' => 1],
    ]])->assertForbidden();

    $accountant = $this->portalMember(CompanyRole::Accountant);
    expect(($this->props)('', $accountant)['can']['manage'])->toBeFalse();
    $this->actingAs($accountant)->post('/app/purchasing/suggestions/orders', ['lines' => [
        ['shopId' => $this->leeds->id, 'supplierId' => F::SUPPLIER, 'productId' => F::COLA, 'cases' => 1],
    ]])->assertForbidden();

    $staff = $this->portalMember(CompanyRole::Staff);
    $this->actingAs($staff)->get('/app/purchasing/suggestions')->assertForbidden();
    $this->actingAs($staff)->postJson('/app/purchasing/suggestions/note')->assertForbidden();

    auth()->logout();
    $this->get('/app/purchasing/suggestions')->assertRedirect('/login');
    expect(($this->orders)()->count())->toBe(0);
});

test('the AI note summarises only the flagged lines; without AI the page still works', function () {
    $this->fake->replyWith('- Coca-Cola at Leeds runs out before the next delivery.');

    $this->actingAs($this->owner)->postJson('/app/purchasing/suggestions/note?shop='.$this->bradford->id)->assertOk()
        ->assertExactJson(['note' => '- Coca-Cola at Leeds runs out before the next delivery.']);

    $sent = $this->fake->lastRequest()->lastUserText();
    expect($sent)->toContain('Coca-Cola 500ml')->toContain('runsOut')->toContain('Bradford')->not->toContain('Leeds')
        ->and($this->fake->lastRequest()->feature->value)->toBe('reorderSuggestions')
        ->and(DB::table('ai_usage')->where('feature', 'reorderSuggestions')->count())->toBe(1);

    $this->fake->notConfigured();
    $this->actingAs($this->owner)->postJson('/app/purchasing/suggestions/note')->assertOk()->assertJsonPath('note', null)
        ->assertJsonStructure(['message']);
    expect(($this->props)()['ai']['available'])->toBeFalse()->and(($this->props)()['lines'])->toHaveCount(2);
});
