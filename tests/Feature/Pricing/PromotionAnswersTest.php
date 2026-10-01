<?php

use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Promotions\Support\PriceTiers;
use App\Domain\Promotions\Support\PromotionSummary;
use App\Domain\Promotions\Support\PromotionTypes;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\TillData\Models\PromotionRule;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenancy\TenancyTestHelpers;
use Tests\Feature\TillData\TillFixtures;

uses(TenancyTestHelpers::class);

/** ANSWERS-2026-10-01 §2: multiBuy, quantityPrice tiers, mixMatch / mealDeal items, days and past-midnight times. */
beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->ids = CatalogueFixtures::seed($this->company);
    $this->product = PricingFixtures::product($this->company, $this->ids);
    $this->as = fn (Closure $fn) => app(CurrentCompany::class)->runAs($this->company, $fn);
    $this->save = fn (?PromotionRule $rule, array $overrides = []) => ($this->as)(function () use ($rule, $overrides) {
        $form = PricingFixtures::offer($this->product->id, $overrides);

        return app(SavePromotion::class)->handle($rule, Arr::except($form, ['items']), $form['items']);
    });
    $this->pulled = function (string $entity, ?string $id = null): array {
        $reply = $this->sync->pull(0)->assertOk();
        expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);

        return collect(Pull::changes($reply))->where('entity', $entity)->when($id !== null, fn ($c) => $c->where('entityId', $id))->values()->all();
    };
    $this->errors = function (array $overrides): array {
        try {
            ($this->save)(null, $overrides);
        } catch (ValidationException $e) {
            return $e->errors();
        }

        return [];
    };
});

test('multiBuy: one tier, dealPrice is the whole deal, priceTiers null', function () {
    $rule = ($this->save)(null, ['name' => '3 for £2', 'type' => 'multiBuy', 'buy_quantity' => '3', 'deal_price' => '2']);

    expect(($this->pulled)('PromotionRule', $rule->id)[0]['payload'])->toMatchArray([
        'name' => '3 for £2', 'type' => 'multiBuy', 'scope' => 'product', 'targetId' => $this->product->id,
        'buyQuantity' => 3, 'dealPrice' => 2, 'priceTiers' => null, 'days' => 'all', 'timeFrom' => null, 'timeTo' => null,
    ]);
});

test('quantityPrice: the tiers go as the till\'s string with prices at 2 dp, memberValue 0', function () {
    $rule = ($this->save)(null, ['name' => '2 for £5, 3 for £7', 'type' => 'quantityPrice', 'price_tiers' => '2=5;3=7.0', 'percent' => '10']);

    expect($rule->price_tiers)->toBe('2=5.00;3=7.00')->and($rule->member_value)->toBe('0.00')
        ->and(PromotionSummary::deal($rule))->toBe('2 for £5.00, 3 for £7.00')
        ->and(($this->pulled)('PromotionRule', $rule->id)[0]['payload'])->toMatchArray([
            'type' => 'quantityPrice', 'scope' => 'product', 'targetId' => $this->product->id, 'priceTiers' => '2=5.00;3=7.00',
            'memberValue' => 0, 'percent' => 0, 'buyQuantity' => 0, 'dealPrice' => 0,
        ]);

    // Another type clears the tiers.
    expect(($this->save)($rule->fresh(), ['type' => 'multiBuy', 'buy_quantity' => '2', 'deal_price' => '5'])->price_tiers)->toBeNull();
});

test('quantityPrice tiers are checked: quantities rise, each ≥ 2 and once, prices above 0 in pence, on one product', function (array $overrides, string $field) {
    expect(($this->errors)(['type' => 'quantityPrice', ...$overrides]))->toHaveKey($field);
})->with([
    'none' => [['price_tiers' => ''], 'price_tiers'],
    'falling' => [['price_tiers' => '3=7.00;2=5.00'], 'price_tiers'],
    'a quantity of one' => [['price_tiers' => '1=1.00;2=1.80'], 'price_tiers'],
    'repeated' => [['price_tiers' => '2=5.00;2=6.00'], 'price_tiers'],
    'free' => [['price_tiers' => '2=0.00'], 'price_tiers'],
    'part pence' => [['price_tiers' => '2=5.001'], 'price_tiers'],
    'JSON' => [['price_tiers' => '[{"qty":2,"price":5}]'], 'price_tiers'],
    'on a category' => [['price_tiers' => '2=5.00', 'scope' => 'category', 'target_id' => 'x'], 'scope'],
]);

test('PriceTiers reads and writes the till\'s string', function () {
    expect(PriceTiers::error('2=5.00;3=7.00'))->toBeNull()
        ->and(PriceTiers::normalise(' 2=5 ; 3=7.5 '))->toBe('2=5.00;3=7.50')
        ->and(PriceTiers::rows('2=5.00;3=7.00'))->toBe([['quantity' => '2', 'price' => '5.00'], ['quantity' => '3', 'price' => '7.00']]);
});

test('mixMatch: the answers\' example shape, with items of group 0 (one left out)', function () {
    DB::table('products')->where('id', $this->product->id)->update(['is_variant_parent' => true]);
    $rule = ($this->save)(null, [
        'name' => 'Any 3 for £2', 'type' => 'mixMatch', 'buy_quantity' => '3', 'deal_price' => '2.00', 'percent' => null,
        'days' => ['monday', 'tuesday', 'wednesday', 'thursday', 'friday'], 'time_from' => '17:00', 'time_to' => '19:00',
        'items' => [
            ['id' => null, 'scope' => 'category', 'target_id' => $this->ids['category'], 'group_no' => 3, 'quantity' => 1, 'is_excluded' => false],
            ['id' => null, 'scope' => 'style', 'target_id' => $this->product->id, 'group_no' => 1, 'quantity' => 1, 'is_excluded' => true],
        ],
    ]);

    $payload = ($this->pulled)('PromotionRule', $rule->id)[0]['payload'];
    expect($payload)->toMatchArray([
        'id' => $rule->id, 'companyId' => TillFixtures::COMPANY, 'name' => 'Any 3 for £2', 'type' => 'mixMatch', 'scope' => 'itemGroup',
        'targetId' => '', 'percent' => 0, 'amountOff' => 0, 'dealPrice' => 2, 'memberValue' => 0, 'buyQuantity' => 3,
        'getQuantity' => 0, 'priceTiers' => null, 'minQuantity' => 0, 'priority' => 0, 'allowStack' => false,
        'isExclusive' => false, 'maxRedemptionsPerSale' => null, 'maxRedemptionsTotal' => null, 'redemptionCount' => 0,
        'couponCode' => '', 'branchId' => null, 'customerGroupId' => null, 'isHfssSafe' => false,
        'effectiveFrom' => '2026-10-01', 'effectiveTo' => null, 'seasonalEventId' => '',
        'days' => 'monday, tuesday, wednesday, thursday, friday', 'timeFrom' => '17:00:00', 'timeTo' => '19:00:00',
        'isActive' => true, 'rowVersion' => 1, 'deletedAt' => null, 'isDeleted' => false,
    ])->toHaveKeys(['createdAt', 'updatedAt']);

    $items = collect(($this->pulled)('PromotionItem'))->pluck('payload')->sortBy('scope')->values();
    expect($items)->toHaveCount(2)
        ->and($items[0])->toMatchArray(['promotionRuleId' => $rule->id, 'scope' => 'category', 'targetId' => $this->ids['category'], 'groupNo' => 0, 'quantity' => 1, 'isExcluded' => false, 'attributeFilter' => null])
        ->and($items[1])->toMatchArray(['scope' => 'style', 'targetId' => $this->product->id, 'groupNo' => 0, 'isExcluded' => true]);
});

test('item checks: a style is a variant parent, at least one item is in, meal deal groups run 1..n', function () {
    $item = fn (string $scope, string $id, int $group = 0, bool $out = false) => ['id' => null, 'scope' => $scope, 'target_id' => $id, 'group_no' => $group, 'quantity' => 1, 'is_excluded' => $out];
    $mix = ['type' => 'mixMatch', 'buy_quantity' => '3', 'deal_price' => '2'];
    $meal = ['type' => 'mealDeal', 'deal_price' => '3.50'];

    expect(($this->errors)([...$mix, 'items' => [$item('style', $this->product->id)]]))->toHaveKey('items.0.target_id')
        ->and(($this->errors)([...$mix, 'items' => [$item('product', $this->product->id, out: true)]]))->toHaveKey('items')
        ->and(($this->errors)([...$meal, 'items' => [$item('product', $this->product->id, 1), $item('category', $this->ids['category'], 3)]]))->toHaveKey('items')
        ->and(($this->errors)([...$meal, 'items' => [$item('product', $this->product->id, 1), $item('category', $this->ids['category'], 2)]]))->toBe([]);
});

test('days are the till\'s flags string; a window may run past midnight, but not start and end together', function () {
    $rule = ($this->save)(null, ['days' => ['saturday', 'friday'], 'time_from' => '22:00', 'time_to' => '02:00']);
    $payload = ($this->pulled)('PromotionRule', $rule->id)[0]['payload'];

    expect($payload)->toMatchArray(['days' => 'friday, saturday', 'timeFrom' => '22:00:00', 'timeTo' => '02:00:00'])
        ->and(PromotionSummary::when($rule->days, $rule->time_from, $rule->time_to))->toBe('Fri, Sat · 22:00–02:00 (past midnight)')
        ->and(PromotionTypes::days([]))->toBe('all')
        ->and(PromotionTypes::days(PromotionTypes::DAYS))->toBe('all')
        ->and(PromotionTypes::days('Sunday, monday'))->toBe('monday, sunday')
        ->and(PromotionTypes::dayList('none'))->toBe(PromotionTypes::DAYS)
        ->and(($this->errors)(['time_from' => '09:00', 'time_to' => '09:00']))->toHaveKey('time_to');
});

test('the offer form posts tiers, days and times, and shows them back', function () {
    $owner = $this->memberOf($this->company, CompanyRole::Owner);
    $this->actingAs($owner)->post('/app/promotions', PricingFixtures::offer($this->product->id, [
        'name' => 'Tiers', 'type' => 'quantityPrice', 'price_tiers' => '2=5.00;3=7.00', 'days' => ['friday', 'saturday'], 'time_from' => '22:00', 'time_to' => '02:00',
    ]))->assertSessionHasNoErrors()->assertRedirect();
    $rule = PromotionRule::withoutCompanyScope()->where('name', 'Tiers')->sole();

    $this->actingAs($owner)->get("/app/promotions/{$rule->id}")->assertOk()->assertInertia(fn (AssertableInertia $page) => $page
        ->where('promotion.price_tiers', [['quantity' => '2', 'price' => '5.00'], ['quantity' => '3', 'price' => '7.00']])
        ->where('promotion.days', ['friday', 'saturday'])->where('promotion.time_to', '02:00')
        ->where('options.types', fn ($types) => collect($types)->pluck('value')->contains('quantityPrice'))
        ->has('options.styles'));
    $this->actingAs($owner)->post('/app/promotions', PricingFixtures::offer($this->product->id, ['days' => ['funday']]))->assertSessionHasErrors('days.0');
});
