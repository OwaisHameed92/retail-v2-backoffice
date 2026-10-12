<?php

use App\Domain\Labels\Actions\QueueOfferDayLabels;
use App\Domain\Labels\Models\LabelQueueItem;
use App\Domain\Promotions\Actions\SavePromotion;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Exceptions;
use Tests\Feature\Catalogue\CatalogueFixtures;
use Tests\Feature\Pricing\PricingFixtures;
use Tests\Feature\Shared\FailingBusiness;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * Audit DATA-8: labels:queue-offers isolates its businesses. An offer of one business that fails (here the first
 * offer the run takes) is reported with its company id, every other offer is still queued, and the command exits
 * non-zero naming the business.
 */

beforeEach(function () {
    Exceptions::fake();
    $this->travelTo('2026-10-05 09:00:00');
    $sync = new SyncApiFixtures($this);
    $company = $sync->company;
    $ids = CatalogueFixtures::seed($company);
    $product = PricingFixtures::product($company, $ids);
    app(CurrentCompany::class)->runAs($company, fn () => app(SavePromotion::class)->handle(null, Arr::except(PricingFixtures::offer($product->id, ['effective_to' => '2026-10-06']), ['items'])));
    LabelQueueItem::withoutCompanyScope()->delete();

    // Another business's offer that also ended yesterday, with no first day, taken first by the run (lowest id).
    $this->broken = Company::factory()->create(['name' => 'Patel News']);
    Branch::factory()->forCompany($this->broken)->create(['code' => 'PTL', 'name' => 'Patel']);
    DB::table('promotion_rules')->insert([
        'id' => '00000000000000000000000001', 'company_id' => $this->broken->id, 'name' => 'Crisps 10% off', 'type' => 'percent',
        'scope' => 'product', 'target_id' => '00000000000000000000000002', 'percent' => '10.0000', 'effective_to' => '2026-10-06', 'is_active' => true,
    ]);
    $this->travelTo('2026-10-07 00:10:00');
});

test('one business\'s failing offer does not stop the others', function () {
    $failing = FailingBusiness::on('branches', [$this->broken->id]);
    $queue = app(QueueOfferDayLabels::class);

    expect($queue->handle())->toBe(2)
        ->and($queue->failedCompanyIds())->toBe([$this->broken->id]);
    $failing->stop();
    Exceptions::assertReported(fn (RuntimeException $e) => $e->getMessage() === "Simulated failure for business {$this->broken->id}");
});

test('an offer with no first day that ended yesterday no longer stops the run', function () {
    $this->artisan('labels:queue-offers')->expectsOutputToContain('Shelf labels queued: 2.')->assertSuccessful();

    Exceptions::assertNothingReported();
});

test('labels:queue-offers exits non-zero naming the business that failed', function () {
    FailingBusiness::on('branches', [$this->broken->id]);

    $this->artisan('labels:queue-offers')->expectsOutputToContain('Shelf labels queued: 2.')->expectsOutputToContain($this->broken->id)->assertFailed();
});
