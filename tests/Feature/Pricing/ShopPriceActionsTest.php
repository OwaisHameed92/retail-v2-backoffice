<?php

use App\Domain\Pricing\Actions\CancelScheduledPrice;
use App\Domain\Pricing\Actions\EndShopPrice;
use App\Domain\Pricing\Actions\SetEveryShopPrice;
use App\Domain\Pricing\Actions\SetShopPrice;
use App\Domain\Pricing\Support\ShopPrices;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\BranchPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 4.3: SetShopPrice, EndShopPrice, SetEveryShopPrice, CancelScheduledPrice, and what each shop's till pulls. */
beforeEach(function () {
    $this->travelTo('2026-10-05 09:00:00');
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->product = PricingFixtures::product($this->company, CatalogueFixtures::seed($this->company));
    $this->as = fn (Closure $fn) => app(CurrentCompany::class)->runAs($this->company, $fn);
    $this->pulled = fn (bool $bradford = false) => collect(Pull::changes($this->sync->pull(0, bradford: $bradford)->assertOk()))
        ->where('entity', 'BranchPrice')->values();
    $this->tillPrice = fn (string $id, array $overrides = []) => $this->sync->push([TillFixtures::envelope('BranchPrice', [
        ...TillFixtures::sample('entities/BranchPrice.json'), 'id' => $id, 'productId' => $this->product->id, 'productUnitId' => null,
        'branchId' => TillFixtures::LEEDS, 'price' => 1.39, 'validFromUtc' => '2026-10-05T08:00:00Z', 'validToUtc' => null, ...$overrides,
    ], 1)])->assertOk()->assertJsonPath('accepted', 1);
});

test('a shop price is a new row sent to that shop only, audited', function () {
    $row = ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '1.29'));

    expect($row->branch_id)->toBe($this->sync->bradford->id)->and($row->price)->toBe('1.29')
        ->and($row->valid_from_utc->toIso8601ZuluString())->toBe('2026-10-05T09:00:00Z')
        ->and(($this->pulled)())->toHaveCount(0);

    $bradford = ($this->pulled)(true);
    expect($bradford)->toHaveCount(1)->and($bradford[0]['payload']['price'])->toBe(1.29)
        ->and($bradford[0]['payload']['branchId'])->toBe(TillFixtures::BRADFORD)
        ->and(AuditLog::query()->where('action', 'price.shop_set')->count())->toBe(1);
});

test('a shop price is refused for a closed shop, a unit of another product, or an end in the past', function () {
    ($this->as)(function () {
        $this->sync->bradford->forceFill(['is_active' => false])->save();
        expect(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '1.29'))->toThrow(ValidationException::class)
            ->and(fn () => app(SetShopPrice::class)->handle($this->sync->leeds, $this->product, '01K5T0Q8C4000000000000U999', '1.29'))->toThrow(ValidationException::class)
            ->and(fn () => app(SetShopPrice::class)->handle($this->sync->leeds, $this->product, null, '1.29', null, CarbonImmutable::parse('2026-10-01')))->toThrow(ValidationException::class);
    });

    expect(BranchPrice::withoutCompanyScope()->count())->toBe(0);
});

test('ending a shop price ends every live row of that shop, the till\'s own too, and sends the ends to that shop', function () {
    ($this->tillPrice)('01K5W2B9J000000000BP000101');
    $later = ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->leeds, $this->product, null, '1.10', CarbonImmutable::parse('2026-11-01', 'UTC')));
    ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '1.20'));
    $this->travel(1)->minutes();

    $ended = ($this->as)(fn () => app(EndShopPrice::class)->handle($this->sync->leeds, $this->product));

    $till = BranchPrice::withoutCompanyScope()->find('01K5W2B9J000000000BP000101');
    expect($ended)->toBe(1)
        ->and($till->valid_to_utc?->toIso8601ZuluString())->toBe('2026-10-05T09:01:00Z')
        ->and($till->price)->toBe('1.39')->and($till->row_version)->toBe(2)->and($till->origin_branch_id)->toBeNull()
        ->and(BranchPrice::withoutCompanyScope()->find($later->id)->valid_to_utc)->toBeNull()
        ->and(($this->as)(fn () => app(EndShopPrice::class)->handle($this->sync->leeds, $this->product)))->toBe(0);

    $leeds = ($this->pulled)()->keyBy('entityId');
    expect($leeds->keys()->sort()->values()->all())->toBe(collect(['01K5W2B9J000000000BP000101', $later->id])->sort()->values()->all())
        ->and($leeds['01K5W2B9J000000000BP000101']['payload']['validToUtc'])->toBe('2026-10-05T09:01:00Z')
        ->and(($this->pulled)(true))->toHaveCount(1)
        ->and(AuditLog::query()->where('action', 'price.shop_ended')->count())->toBe(1);
});

test('the portal still never edits a till\'s price beyond ending it', function () {
    ($this->tillPrice)('01K5W2B9J000000000BP000102');

    expect(fn () => ($this->as)(fn () => BranchPrice::query()->findOrFail('01K5W2B9J000000000BP000102')->forceFill(['price' => '9.99', 'valid_to_utc' => CarbonImmutable::now('UTC')])->save()))
        ->toThrow(LogicException::class)
        ->and(fn () => ($this->as)(fn () => BranchPrice::query()->findOrFail('01K5W2B9J000000000BP000102')->forceFill(['valid_from_utc' => CarbonImmutable::now('UTC')])->save()))
        ->toThrow(LogicException::class);
});

test('every shop moves the business price and ends only the chosen shops\' own prices', function () {
    ($this->tillPrice)('01K5W2B9J000000000BP000103');
    ($this->as)(fn () => app(SetShopPrice::class)->handle($this->sync->bradford, $this->product, null, '1.20'));
    $this->travel(1)->minutes();

    $saved = ($this->as)(fn () => app(SetEveryShopPrice::class)->handle($this->product, '1.55', [$this->sync->leeds->id]));

    expect($saved->product->sell_price)->toBe('1.55')->and($saved->changed)->toBe(['sell_price']);

    ($this->as)(function () {
        $live = ShopPrices::live([$this->product->id], [$this->sync->leeds->id, $this->sync->bradford->id])[$this->product->id];
        expect($live)->toHaveKey($this->sync->bradford->id)->not->toHaveKey($this->sync->leeds->id);
    });

    $leeds = Pull::changes($this->sync->pull(0)->assertOk());
    $bradford = Pull::changes($this->sync->pull(0, bradford: true)->assertOk());
    expect(collect($leeds)->firstWhere('entity', 'Product')['payload']['sellPrice'])->toBe(1.55)
        ->and(collect($bradford)->firstWhere('entity', 'Product')['payload']['sellPrice'])->toBe(1.55)
        ->and(collect($leeds)->firstWhere('entity', 'BranchPrice')['payload']['validToUtc'])->toBe('2026-10-05T09:01:00Z')
        ->and(collect($bradford)->firstWhere('entity', 'BranchPrice')['payload']['validToUtc'])->toBeNull();

    expect(fn () => ($this->as)(fn () => app(SetEveryShopPrice::class)->handle($this->product, '1.60', ['01K5T0Q8C4000000000000B999'])))
        ->toThrow(ValidationException::class);
});

test('a scheduled price can be cancelled (never live); a started one cannot', function () {
    [$scheduled, $now] = ($this->as)(fn () => [
        app(SetShopPrice::class)->handle($this->sync->leeds, $this->product, null, '0.99', CarbonImmutable::parse('2026-10-10', 'UTC')),
        app(SetShopPrice::class)->handle($this->sync->leeds, $this->product, null, '1.09'),
    ]);

    $cancelled = ($this->as)(fn () => app(CancelScheduledPrice::class)->handle($scheduled));

    expect($cancelled->valid_to_utc?->toIso8601ZuluString())->toBe('2026-10-10T00:00:00Z')
        ->and(ShopPrices::status($cancelled))->toBe('cancelled')
        ->and(ShopPrices::status($now))->toBe('live')
        ->and(fn () => ($this->as)(fn () => app(CancelScheduledPrice::class)->handle($now)))->toThrow(ValidationException::class);

    $this->travelTo('2026-10-11 09:00:00');
    ($this->as)(fn () => expect(ShopPrices::live([$this->product->id], [$this->sync->leeds->id])[$this->product->id][$this->sync->leeds->id]['']->id)->toBe($now->id));
    expect(DB::table('branch_prices')->count())->toBe(2);
});
