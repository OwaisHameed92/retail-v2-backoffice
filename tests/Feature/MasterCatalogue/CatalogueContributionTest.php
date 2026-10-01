<?php

use App\Domain\MasterCatalogue\Actions\CollectUnknownBarcodes;
use App\Domain\MasterCatalogue\Enums\ContributionStatus;
use App\Domain\MasterCatalogue\Jobs\RecordContributionsJob;
use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\MasterCatalogue\Support\PackSize;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\MasterCatalogue\MasterFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Starter catalogue (gap #7): tills' unknown barcodes reach the admin review queue anonymously, unless opted out. */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->batch = function (array $products, int $seq = 1): array {
        $changes = [];

        foreach ($products as $i => [$barcode, $name]) {
            $productId = sprintf('01K5T0Q8C40000000000P%05d', $seq + $i);
            $changes[] = TillFixtures::envelope('Product', Pull::payload('Product', $productId, ['name' => $name, 'sellPrice' => 2.49, 'costPrice' => 1.1]), $seq + $i * 2);
            $changes[] = TillFixtures::envelope('ProductBarcode', Pull::payload('ProductBarcode', sprintf('01K5T0Q8C40000000000B%05d', $seq + $i), [
                'productId' => $productId, 'barcode' => $barcode,
            ]), $seq + $i * 2 + 1);
        }

        return $changes;
    };
});

test('a till push queues only the barcode and name of products the catalogue does not know', function () {
    Queue::fake();
    MasterFixtures::product(['barcode' => '5000157024671']);

    $this->sync->push(($this->batch)([
        ['5012345678900', 'Mystery Crisps 40g'],          // unknown: collected
        ['5000157024671', 'Heinz Beans'],                 // in the catalogue
        ['0036000291452', 'Kleenex 70'],                  // unknown UPC/EAN-13
        ['2012345678903', 'Loose bananas'],               // in-store number: never shared
        ['12345', 'Shop code'],                           // not a GTIN
    ]));

    Queue::assertPushed(RecordContributionsJob::class, function (RecordContributionsJob $job) {
        $serialised = serialize($job);

        return $job->items === [['barcode' => '5012345678900', 'name' => 'Mystery Crisps 40g'], ['barcode' => '0036000291452', 'name' => 'Kleenex 70']]
            && ! str_contains($serialised, $this->sync->company->id) && ! str_contains($serialised, $this->sync->leeds->id)
            && ! str_contains($serialised, '2.49') && ! str_contains($serialised, TillFixtures::COMPANY);
    });
});

test('the review queue holds barcode, name, size and a count: no business, price or till column exists', function () {
    $this->sync->push(($this->batch)([['5012345678900', 'Mystery Crisps 4 x 25g']]))->assertOk();
    $this->sync->push(($this->batch)([['5012345678900', 'Mystery Crisps 4 x 25g']], 50), bradford: true)->assertOk();

    $row = CatalogueContribution::query()->sole();

    expect($row->barcode)->toBe('5012345678900')
        ->and($row->name)->toBe('Mystery Crisps 4 x 25g')
        ->and($row->size()->label())->toBe('4 x 25g')
        ->and($row->seen_count)->toBe(2)
        ->and($row->status)->toBe(ContributionStatus::Pending)
        ->and(Schema::getColumnListing('catalogue_contributions'))->not->toContain('company_id', 'branch_id', 'register_id', 'sell_price', 'cost_price');
});

test('a business that opted out shares nothing; a broken collector never fails the push', function () {
    $this->sync->company->forceFill(['share_unknown_barcodes' => false])->save();
    $this->sync->push(($this->batch)([['5012345678900', 'Mystery Crisps 40g']]))->assertOk();

    expect(CatalogueContribution::query()->count())->toBe(0);

    $this->sync->company->forceFill(['share_unknown_barcodes' => true])->save();
    app(CollectUnknownBarcodes::class)->handle($this->sync->company, [['entity' => 'ProductBarcode', 'op' => 'I', 'payload' => 'not an array'], 'junk']);

    expect(CatalogueContribution::query()->count())->toBe(0);
});

test('an approved or catalogued barcode is not queued again; a rejected one only counts sightings', function () {
    CatalogueContribution::query()->create(['barcode' => '5012345678900', 'name' => 'Mystery', 'status' => ContributionStatus::Rejected, 'seen_count' => 1]);
    $this->sync->push(($this->batch)([['5012345678900', 'Mystery Crisps 40g']]))->assertOk();

    expect(CatalogueContribution::query()->sole()->seen_count)->toBe(2)
        ->and(CatalogueContribution::query()->sole()->status)->toBe(ContributionStatus::Rejected);
});

test('GTIN check digits, in-store prefixes and pack sizes are read correctly', function () {
    expect(Gtin::normalise('5000157024671'))->toBe('5000157024671')
        ->and(Gtin::normalise('5000157024672'))->toBeNull()
        ->and(Gtin::normalise('96385074'))->toBe('96385074')
        ->and(Gtin::isInStore('2012345678903'))->toBeTrue()
        ->and(Gtin::variants('036000291452'))->toBe(['036000291452', '0036000291452'])
        ->and(PackSize::fromName('Carlsberg Pilsner 4 x 440ml')->label())->toBe('4 x 440ml')
        ->and(PackSize::fromName('Coca-Cola 1.25L')->volumeMl())->toBe('1250')
        ->and(PackSize::fromName('Heinz Baked Beans 415g')->massKg())->toBe('0.415')
        ->and(PackSize::fromName('Weetabix 24 Pack')->label())->toBe('24 pack')
        ->and(PackSize::fromCells('70', 'cl', null)->label())->toBe('70cl');
});
