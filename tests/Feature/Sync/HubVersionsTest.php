<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Actions\PublishHubChange;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.5: the pull version counter (HubVersions), HubOwnedRow's after-commit stamp and PublishHubChange. */
beforeEach(function () {
    [$this->company, $this->leeds] = TillFixtures::tenant();
    $this->product = fn (string $id) => [...TillFixtures::sample('entities/Product.json'), 'id' => $id];
    $this->version = fn (string $id) => DB::table('products')->where('id', $id)->value('hub_version');
});

test('every save gets a distinct, increasing version; saves in one transaction are stamped in order when it commits', function () {
    Pull::portalCreate($this->company, 'Product', ($this->product)('01K5T0Q8C4000000000000P101'));
    Pull::portalCreate($this->company, 'Product', ($this->product)('01K5T0Q8C4000000000000P102'));

    DB::transaction(function () {
        Pull::portalCreate($this->company, 'Product', ($this->product)('01K5T0Q8C4000000000000P103'));
        Pull::portalUpdate($this->company, 'Product', '01K5T0Q8C4000000000000P101', ['sell_price' => '2.00']);

        expect(($this->version)('01K5T0Q8C4000000000000P103'))->toBeNull();   // not visible before the commit
    });

    expect([($this->version)('01K5T0Q8C4000000000000P102'), ($this->version)('01K5T0Q8C4000000000000P103'), ($this->version)('01K5T0Q8C4000000000000P101')])
        ->toBe([2, 3, 4])
        ->and(app(HubVersions::class)->current($this->company->id))->toBe(4);
});

test('a rolled-back save uses no version; a stamped row is never stamped twice', function () {
    Pull::portalCreate($this->company, 'Product', ($this->product)('01K5T0Q8C4000000000000P101'));

    try {
        DB::transaction(function () {
            Pull::portalCreate($this->company, 'Product', ($this->product)('01K5T0Q8C4000000000000P102'));

            throw new RuntimeException('rolled back');
        });
    } catch (RuntimeException) {
    }

    $versions = app(HubVersions::class);

    expect($versions->stamp($this->company->id, 'Product', ['01K5T0Q8C4000000000000P101']))->toBeNull()
        ->and($versions->stampPending($this->company->id))->toBeNull()
        ->and($versions->stamp($this->company->id, 'Sale', ['01K5VB000000000SR001000482']))->toBeNull()   // till-owned: never
        ->and($versions->current($this->company->id))->toBe(1)
        ->and(DB::table('products')->count())->toBe(1);
});

test('rows accepted from a till are stamped at the next pull, parents first, keeping their origin branch', function () {
    TillFixtures::apply($this->company, $this->leeds, [
        TillFixtures::envelope('ProductBarcode', TillFixtures::sample('entities/ProductBarcode.json'), 1),
        TillFixtures::envelope('Product', TillFixtures::sample('entities/Product.json'), 2),
        TillFixtures::envelope('Category', TillFixtures::sample('entities/Category.json'), 3),
    ]);

    expect(DB::table('products')->value('hub_version'))->toBeNull();

    expect(app(HubVersions::class)->stampPending($this->company->id))->toBe(3)
        ->and(DB::table('categories')->value('hub_version'))->toBe(1)
        ->and(DB::table('products')->value('hub_version'))->toBe(2)
        ->and(DB::table('product_barcodes')->value('hub_version'))->toBe(3)
        ->and(DB::table('products')->value('origin_branch_id'))->toBe(TillFixtures::LEEDS);
});

test('PublishHubChange publishes a change made without Eloquent as the portal\'s', function () {
    TillFixtures::apply($this->company, $this->leeds, [TillFixtures::envelope('Product', TillFixtures::sample('entities/Product.json'), 1)]);
    app(HubVersions::class)->stampPending($this->company->id);
    $before = (array) DB::table('products')->first();

    DB::table('products')->update(['sell_price' => '1.99']);
    $version = app(PublishHubChange::class)->handle($this->company->id, 'Product', ['01K5T0Q8C4000000000000P001']);
    $after = (array) DB::table('products')->first();

    expect($version)->toBe(2)
        ->and($after['hub_version'])->toBe(2)
        ->and($after['origin_branch_id'])->toBeNull()
        ->and($after['hub_hash'])->not->toBe($before['hub_hash'])
        ->and($after['hub_edited_at'])->not->toBeNull()
        ->and(fn () => app(PublishHubChange::class)->handle($this->company->id, 'Sale', ['x']))->toThrow(InvalidArgumentException::class);
});

test('a soft delete and a restore on the portal each get a new version', function () {
    Pull::portalCreate($this->company, 'Product', ($this->product)('01K5T0Q8C4000000000000P101'));
    Pull::portalDelete($this->company, 'Product', '01K5T0Q8C4000000000000P101');

    expect(($this->version)('01K5T0Q8C4000000000000P101'))->toBe(2);

    app(CurrentCompany::class)->runAs($this->company, fn () => Product::withTrashed()->findOrFail('01K5T0Q8C4000000000000P101')->restore());

    expect(($this->version)('01K5T0Q8C4000000000000P101'))->toBe(3)
        ->and(DB::table('products')->value('deleted_at'))->toBeNull();
});
