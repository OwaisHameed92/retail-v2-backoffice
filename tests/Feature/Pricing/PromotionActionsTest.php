<?php

use App\Domain\Promotions\Actions\EndPromotion;
use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\PromotionItem;
use App\Domain\TillData\Models\PromotionRule;
use Illuminate\Support\Arr;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 4.3: SavePromotion and EndPromotion, and every shop's till receiving the offers in its pull. */
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
});

test('creates an every-shop offer with a ULID; isGroupOffer is worked out, never taken from input; every till pulls it', function () {
    $rule = ($this->save)(null, ['min_quantity' => '2', 'is_group_offer' => false, 'amount_off' => '9.99']);

    expect($rule->id)->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($rule->is_group_offer)->toBeTrue()
        ->and($rule->percent)->toBe('10.0000')
        ->and($rule->amount_off)->toBe('0.00')
        ->and($rule->branch_id)->toBeNull()
        ->and($rule->row_version)->toBe(1)
        ->and(AuditLog::query()->where('action', 'promotion.created')->count())->toBe(1);

    foreach ([false, true] as $bradford) {
        $change = collect(Pull::changes($this->sync->pull(0, bradford: $bradford)->assertOk()))->firstWhere('entity', 'PromotionRule');
        expect($change['payload']['branchId'])->toBeNull()->and($change['payload']['isGroupOffer'])->toBeTrue()
            ->and($change['payload']['percent'])->toEqual(10)->and($change['payload']['effectiveFrom'])->toBe('2026-10-01');
    }
});

test('a shop-only offer carries that shop (till id) to every till; an edit raises rowVersion; no change writes nothing', function () {
    $rule = ($this->save)(null, ['branch_id' => $this->sync->leeds->id, 'type' => 'multiBuy', 'buy_quantity' => '3', 'deal_price' => '2', 'percent' => '50']);
    expect($rule->percent)->toBe('0.0000')->and($rule->deal_price)->toBe('2.00')->and($rule->is_group_offer)->toBeFalse();

    $change = collect(Pull::changes($this->sync->pull(0, bradford: true)->assertOk()))->firstWhere('entity', 'PromotionRule');
    expect($change['payload']['branchId'])->toBe(TillFixtures::LEEDS);

    $same = ($this->save)($rule->fresh(), ['branch_id' => $this->sync->leeds->id, 'type' => 'multiBuy', 'buy_quantity' => '3', 'deal_price' => '2.00', 'percent' => '50']);
    expect($same->row_version)->toBe(1);

    $edited = ($this->save)($rule->fresh(), ['branch_id' => $this->sync->leeds->id, 'type' => 'multiBuy', 'buy_quantity' => '3', 'deal_price' => '2.50']);
    expect($edited->row_version)->toBe(2)->and($edited->id)->toBe($rule->id)->and($edited->deal_price)->toBe('2.50');
});

test('a meal deal is built from items in groups; items keep ids, removed ones are deleted', function () {
    $other = PricingFixtures::product($this->company, $this->ids, ['name' => 'Coke 500ml', 'sku' => 'CCE-500']);
    $items = [
        ['id' => null, 'scope' => 'product', 'target_id' => $this->product->id, 'group_no' => 1, 'quantity' => 1, 'is_excluded' => false],
        ['id' => null, 'scope' => 'product', 'target_id' => $other->id, 'group_no' => 2, 'quantity' => 1, 'is_excluded' => false],
    ];
    $rule = ($this->save)(null, ['type' => 'mealDeal', 'deal_price' => '3.50', 'items' => $items]);

    $saved = PromotionItem::withoutCompanyScope()->where('promotion_rule_id', $rule->id)->orderBy('group_no')->get();
    expect($rule->scope?->value)->toBe('itemGroup')->and($rule->target_id)->toBe('')->and($saved)->toHaveCount(2);

    $items[0]['id'] = $saved[0]->id;
    $items[1] = ['id' => null, 'scope' => 'category', 'target_id' => $this->ids['category'], 'group_no' => 2, 'quantity' => 1, 'is_excluded' => false];
    ($this->save)($rule->fresh(), ['type' => 'mealDeal', 'deal_price' => '3.50', 'items' => $items]);

    $after = PromotionItem::withoutCompanyScope()->where('promotion_rule_id', $rule->id)->orderBy('group_no')->get();
    expect($after->pluck('id')->first())->toBe($saved[0]->id)->and($after)->toHaveCount(2)
        ->and(PromotionItem::withoutCompanyScope()->onlyTrashed()->whereKey($saved[1]->id)->exists())->toBeTrue();

    expect(fn () => ($this->save)(null, ['type' => 'mealDeal', 'deal_price' => '3.50', 'items' => [$items[0]]]))->toThrow(ValidationException::class);
});

test('checks what each type needs, the target, the shop, the dates, times and coupon', function (array $overrides, string $field) {
    try {
        ($this->save)(null, $overrides);
        $this->fail('Expected a validation error');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }
})->with([
    'no percent' => [['percent' => '0'], 'percent'],
    'over 100%' => [['percent' => '101'], 'percent'],
    'fixed off without amount' => [['type' => 'fixedOff'], 'amount_off'],
    'multi-buy of one' => [['type' => 'multiBuy', 'buy_quantity' => '1', 'deal_price' => '1'], 'buy_quantity'],
    'unknown product' => [['target_id' => '01K5T0Q8C4000000000000P999'], 'target_id'],
    'price tiers missing' => [['type' => 'quantityPrice'], 'price_tiers'],
    'unknown shop' => [['branch_id' => '01K5T0Q8C4000000000000B999'], 'branch_id'],
    'ends before it starts' => [['effective_to' => '2026-09-30'], 'effective_to'],
    'one time only' => [['time_from' => '09:00'], 'time_to'],
    'coupon without code' => [['requires_coupon' => true], 'coupon_code'],
]);

test('another business\'s product cannot be the target', function () {
    $company = Company::factory()->create();
    $theirs = PricingFixtures::product($company, CatalogueFixtures::seed($company));

    expect(fn () => ($this->save)(null, ['target_id' => $theirs->id]))->toThrow(ValidationException::class);
});

test('ending an offer stops it today and sends the change; a till-made rule with a till-only type can be ended', function () {
    $rule = ($this->save)(null);
    $ended = ($this->as)(fn () => app(EndPromotion::class)->handle($rule));

    expect($ended->is_active)->toBeFalse()->and($ended->effective_to?->toDateString())->toBe('2026-10-05')->and($ended->row_version)->toBe(2)
        ->and(($this->as)(fn () => app(EndPromotion::class)->handle($ended))->row_version)->toBe(2);

    $this->sync->push([TillFixtures::envelope('PromotionRule', Pull::payload('PromotionRule', '01K5W2B9J000000000PR000101', [
        'type' => 'quantityPrice', 'branchId' => TillFixtures::LEEDS, 'effectiveFrom' => '2026-10-01', 'effectiveTo' => null, 'isActive' => true,
    ]), 1)])->assertOk();
    $till = ($this->as)(fn () => app(EndPromotion::class)->handle(PromotionRule::query()->findOrFail('01K5W2B9J000000000PR000101')));
    expect($till->is_active)->toBeFalse()->and($till->branch_id)->toBe($this->sync->leeds->id);
});
