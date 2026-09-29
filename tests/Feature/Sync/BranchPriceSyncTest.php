<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Actions\SetBranchPrice;
use App\Domain\TillData\Models\BranchPrice;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Contract v1.4.1 §10.5 and SHOP-OR-EVERY-SHOP.md: a shop's own price, written by the portal and that shop's till. */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->price = fn (string $id, array $overrides = []) => [...TillFixtures::sample('entities/BranchPrice.json'), 'id' => $id, ...$overrides];
    $this->push = fn (array $payload, int $seq, string $op = 'I', bool $bradford = false) => $this->sync->push(
        [TillFixtures::envelope('BranchPrice', $payload, $seq, ['op' => $op, 'version' => $payload['rowVersion']])],
        bradford: $bradford,
    );
    $this->as = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

test('a shop\'s till pushes its own price (I) and ends the old one (U, validToUtc), including a portal row', function () {
    $portal = ($this->as)(fn () => app(SetBranchPrice::class)->handle($this->sync->leeds, '01K5T0Q8C4000000000000P001', null, '1.25', CarbonImmutable::parse('2026-09-01', 'UTC')));

    $new = ($this->price)('01K5W2B9J000000000BP000010', ['price' => 1.39, 'validFromUtc' => '2026-09-29T10:00:00Z', 'validToUtc' => null]);
    ($this->push)($new, 1)->assertOk()->assertJsonPath('accepted', 1);

    $ended = [
        ...($this->price)($portal->id, ['price' => 1.25, 'validFromUtc' => '2026-09-01T00:00:00Z', 'validToUtc' => '2026-09-29T10:00:00Z']),
        'rowVersion' => 2, 'updatedAt' => '2026-09-29T10:00:00Z',
    ];
    ($this->push)($ended, 2, 'U')->assertOk()->assertJsonPath('accepted', 1);

    $rows = BranchPrice::withoutCompanyScope()->orderBy('valid_from_utc')->get();
    expect($rows)->toHaveCount(2)
        ->and($rows->pluck('branch_id')->unique()->all())->toBe([$this->sync->leeds->id])
        ->and($rows->pluck('origin_branch_id')->unique()->all())->toBe([$this->sync->leeds->id])
        ->and($rows[0]->valid_to_utc?->toIso8601ZuluString())->toBe('2026-09-29T10:00:00Z')
        ->and($rows[1]->price)->toBe('1.39');
});

test('a price for another shop is refused (the row is rejected, nothing stored)', function () {
    $bradford = ($this->price)('01K5W2B9J000000000BP000011', ['branchId' => TillFixtures::BRADFORD]);

    $reply = ($this->push)($bradford, 1)->assertStatus(422)->assertJsonPath('code', 'row.invalid');

    expect($reply->json('message'))->toContain('another branch')
        ->and(DB::table('branch_prices')->count())->toBe(0);
});

test('pull: a shop price goes only to its own shop, and never back to the shop that set it', function () {
    ($this->push)(($this->price)('01K5W2B9J000000000BP000012'), 1)->assertOk();
    ($this->as)(fn () => app(SetBranchPrice::class)->handle($this->sync->bradford, '01K5T0Q8C4000000000000P001', null, '1.49'));

    $leeds = Pull::changes($this->sync->pull(0)->assertOk());
    $bradford = $this->sync->pull(0, bradford: true)->assertOk();
    expect(SyncApiFixtures::schemaErrors($bradford, 'pull-reply.schema.json'))->toBe([]);
    $bradford = Pull::changes($bradford);

    expect(collect($leeds)->where('entity', 'BranchPrice'))->toHaveCount(0)
        ->and(collect($bradford)->where('entity', 'BranchPrice'))->toHaveCount(1)
        ->and($bradford[0]['branchId'])->toBe(TillFixtures::BRADFORD)
        ->and($bradford[0]['payload']['branchId'])->toBe(TillFixtures::BRADFORD)
        ->and($bradford[0]['payload']['price'])->toBe(1.49);
});

test('the portal never edits a till\'s price: a new price is a new, later row', function () {
    $till = ($this->price)('01K5W2B9J000000000BP000013', ['validFromUtc' => '2026-09-29T10:00:00Z', 'validToUtc' => null]);
    ($this->push)($till, 1)->assertOk();

    $mine = ($this->as)(fn () => app(SetBranchPrice::class)->handle($this->sync->leeds, $till['productId'], null, '1.30', CarbonImmutable::parse('2026-09-29 10:00:00', 'UTC')));

    expect($mine->id)->not->toBe($till['id'])
        ->and($mine->valid_from_utc->toIso8601ZuluString())->toBe('2026-09-29T10:00:01Z')
        ->and($mine->origin_branch_id)->toBeNull()
        ->and(BranchPrice::withoutCompanyScope()->count())->toBe(2)
        ->and(fn () => ($this->as)(fn () => BranchPrice::query()->findOrFail($till['id'])->forceFill(['price' => '9.99'])->save()))
        ->toThrow(LogicException::class);

    ($this->as)(function () use ($mine) {
        $live = BranchPrice::query()->forBranch($this->sync->leeds)->forProduct($mine->product_id)->liveAt('2026-09-30 00:00:00')->first();
        expect($live?->id)->toBe($mine->id)->and(DB::table('branch_prices')->where('id', '01K5W2B9J000000000BP000013')->value('price'))->toEqual(1.25);
    });
});

test('SetBranchPrice validates the price and dates', function () {
    ($this->as)(function () {
        expect(fn () => app(SetBranchPrice::class)->handle($this->sync->leeds, 'P', null, '1.999'))->toThrow(ValidationException::class)
            ->and(fn () => app(SetBranchPrice::class)->handle($this->sync->leeds, 'P', null, '1.00', CarbonImmutable::parse('2026-10-02'), CarbonImmutable::parse('2026-10-01')))
            ->toThrow(ValidationException::class);
    });
});

test('offers and price-change lines keep their shop as sent; a shop rule and an every-shop rule stay two rows', function () {
    $shop = Pull::payload('PromotionRule', '01K5W2B9J000000000PR000001', ['branchId' => TillFixtures::LEEDS, 'name' => 'Bakery 10% (Leeds)']);
    $everyShop = Pull::payload('PromotionRule', '01K5W2B9J000000000PR000002', ['branchId' => null, 'name' => 'Bakery 5%']);
    $line = Pull::payload('PriceChangeLine', '01K5W2B9J000000000PC000001', ['branchId' => TillFixtures::LEEDS, 'batchId' => '01K5W2B9J000000000PB000001']);

    $this->sync->push([
        TillFixtures::envelope('PromotionRule', $shop, 1),
        TillFixtures::envelope('PromotionRule', $everyShop, 2),
        TillFixtures::envelope('PriceChangeLine', $line, 3, ['branchId' => TillFixtures::LEEDS]),
    ])->assertOk()->assertJsonPath('accepted', 3);

    expect(DB::table('promotion_rules')->orderBy('id')->pluck('branch_id')->all())->toBe([$this->sync->leeds->id, null])
        ->and(DB::table('price_change_lines')->value('price_branch_id'))->toBe($this->sync->leeds->id);
});
