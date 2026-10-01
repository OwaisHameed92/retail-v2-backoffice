<?php

use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Labels\Actions\QueueOfferDayLabels;
use App\Domain\Labels\Actions\UpdateLabelQueue;
use App\Domain\Labels\Enums\LabelReason;
use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Pricing\Actions\EndShopPrice;
use App\Domain\Pricing\Actions\SetEveryShopPrice;
use App\Domain\Pricing\Actions\SetShopPrice;
use App\Domain\Promotions\Actions\EndPromotion;
use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Tenancy\CurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Sync\SyncApiFixtures;

/** Gap #6: the label queue fills itself from price and offer changes, deduped per shop and product. */
beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->ids = CatalogueFixtures::seed($this->company);
    $this->product = PricingFixtures::product($this->company, $this->ids);
    $this->as = fn (Closure $fn) => app(CurrentCompany::class)->runAs($this->company, $fn);
    $this->queued = fn () => LabelQueueItem::withoutCompanyScope()->where('pending', true)->orderBy('branch_id')->get();
});

test('a new product is not queued; a business price change queues every open shop selling at the business price', function () {
    expect(($this->queued)())->toHaveCount(0);

    ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '1.20'));
    LabelQueueItem::withoutCompanyScope()->delete();

    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['sell_price' => '1.60']));

    $items = ($this->queued)();
    expect($items)->toHaveCount(1)
        ->and($items[0]->branch_id)->toBe($this->sync->leeds->id)
        ->and($items[0]->reason)->toBe(LabelReason::PriceChange)
        ->and($items[0]->detail)->toBe('£1.45 → £1.60')
        ->and($items[0]->company_id)->toBe($this->company->id);

    // Other edits do not queue.
    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['brand' => 'Hovis']));
    expect(($this->queued)())->toHaveCount(1);
});

test('queuing again before printing dedupes to one row per shop and product; printing then re-queues in place', function () {
    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['sell_price' => '1.60']));
    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['sell_price' => '1.65']));

    $items = ($this->queued)();
    expect($items)->toHaveCount(2)
        ->and($items->pluck('times_queued')->unique()->all())->toBe([2])
        ->and($items[0]->detail)->toBe('£1.60 → £1.65');

    $leeds = $items->firstWhere('branch_id', $this->sync->leeds->id);
    ($this->as)(fn () => app(UpdateLabelQueue::class)->handle($this->sync->leeds, [$leeds->id], 'printed'));
    expect($leeds->fresh()->pending)->toBeFalse()->and($leeds->fresh()->printed_at)->not->toBeNull();

    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['sell_price' => '1.70']));
    expect(LabelQueueItem::withoutCompanyScope()->count())->toBe(2)
        ->and($leeds->fresh()->pending)->toBeTrue()
        ->and($leeds->fresh()->times_queued)->toBe(1);
});

test('a shop price queues that shop only (a scheduled one with its start), and ending it queues it again', function () {
    ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->leeds, $this->product, null, '1.30'));
    $items = ($this->queued)();
    expect($items)->toHaveCount(1)->and($items[0]->branch_id)->toBe($this->sync->leeds->id)
        ->and($items[0]->reason)->toBe(LabelReason::ShopPrice)->and($items[0]->due_at)->toBeNull();

    ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '0.99', CarbonImmutable::parse('2026-11-01 08:00', 'UTC')));
    $bradford = LabelQueueItem::withoutCompanyScope()->where('branch_id', $this->sync->bradford->id)->sole();
    expect($bradford->due_at?->toIso8601ZuluString())->toBe('2026-11-01T08:00:00Z');

    ($this->as)(fn () => app(EndShopPrice::class)->handle($this->sync->leeds, $this->product));
    $leeds = LabelQueueItem::withoutCompanyScope()->where('branch_id', $this->sync->leeds->id)->sole();
    expect($leeds->reason)->toBe(LabelReason::ShopPriceEnded)->and($leeds->times_queued)->toBe(2)->and($leeds->detail)->toBe('Back to £1.45');
});

test('"every shop" queues the shops at the business price and the shops whose own price it ends', function () {
    ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '1.20'));
    LabelQueueItem::withoutCompanyScope()->delete();

    ($this->as)(fn () => app(SetEveryShopPrice::class)->handle($this->product->fresh(), '1.55', [$this->sync->bradford->id]));

    expect(($this->queued)()->pluck('reason', 'branch_id')->all())->toEqual([
        $this->sync->leeds->id => LabelReason::PriceChange, $this->sync->bradford->id => LabelReason::ShopPriceEnded,
    ]);
});

test('a live offer queues its products in its shops; a scheduled one waits for its first day; ending it queues them again', function () {
    $other = PricingFixtures::product($this->company, $this->ids, ['name' => 'Seeded Batch 400g', 'sku' => 'SEED-400', 'barcodes' => []]);

    $rule = ($this->as)(fn () => app(SavePromotion::class)->handle(null, Arr::except(PricingFixtures::offer($this->product->id, ['branch_id' => $this->sync->leeds->id]), ['items'])));
    $items = ($this->queued)();
    expect($items)->toHaveCount(1)->and($items[0]->branch_id)->toBe($this->sync->leeds->id)
        ->and($items[0]->reason)->toBe(LabelReason::PromotionStarted)->and($items[0]->detail)->toBe('Toastie 10% off · 10% off');

    LabelQueueItem::withoutCompanyScope()->delete();
    ($this->as)(fn () => app(SavePromotion::class)->handle(null, Arr::except(PricingFixtures::offer($this->ids['department'], [
        'name' => 'Bakery week', 'scope' => 'department', 'effective_from' => '2026-10-12',
    ]), ['items'])));
    expect(($this->queued)())->toHaveCount(0);

    $this->travelTo('2026-10-12 00:15:00');
    expect(app(QueueOfferDayLabels::class)->handle())->toBe(4) // 2 products × 2 shops
        ->and(($this->queued)()->pluck('product_id')->unique()->sort()->values()->all())->toBe(collect([$this->product->id, $other->id])->sort()->values()->all());

    LabelQueueItem::withoutCompanyScope()->delete();
    ($this->as)(fn () => app(EndPromotion::class)->handle($rule->fresh()));
    $ended = ($this->queued)();
    expect($ended)->toHaveCount(1)->and($ended[0]->reason)->toBe(LabelReason::PromotionEnded)->and($ended[0]->branch_id)->toBe($this->sync->leeds->id);
});

test('an offer whose last day was yesterday is queued by the daily run', function () {
    ($this->as)(fn () => app(SavePromotion::class)->handle(null, Arr::except(PricingFixtures::offer($this->product->id, ['effective_to' => '2026-10-06']), ['items'])));
    LabelQueueItem::withoutCompanyScope()->delete();

    $this->travelTo('2026-10-07 00:10:00');
    $this->artisan('labels:queue-offers')->expectsOutputToContain('Shelf labels queued: 2.')->assertSuccessful();

    expect(($this->queued)()->pluck('reason')->unique()->all())->toEqual([LabelReason::PromotionEnded]);
});

test('inactive products and closed shops are never queued', function () {
    $this->sync->bradford->forceFill(['is_active' => false])->save();
    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['sell_price' => '1.60']));
    expect(($this->queued)()->pluck('branch_id')->all())->toBe([$this->sync->leeds->id]);

    LabelQueueItem::withoutCompanyScope()->delete();
    ($this->as)(fn () => app(SaveProduct::class)->handle($this->product->fresh(), ['sell_price' => '1.70', 'is_active' => false]));
    expect(($this->queued)())->toHaveCount(0);
});
