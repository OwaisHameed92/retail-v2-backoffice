<?php

use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\SyncKeySecret;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/* Security review 7.3: M6 failed sync-key throttle, L10 APP_KEY rotation, L4 the push never moves a row's company. */

beforeEach(function () {
    $this->travelTo('2026-09-29 10:00:00');
    $this->sync = new SyncApiFixtures($this);
});

test('M6: failed Bearers are counted per key and per IP, then locked out with 429 before any lookup', function () {
    config(['sync.failed_auth.per_key' => 3, 'sync.failed_auth.per_ip' => 5]);
    $wrong = 'SSK-0000-0000-0000-0000-0000-0000-0000-0000';
    $other = 'SSK-1111-1111-1111-1111-1111-1111-1111-1111';

    foreach (range(1, 3) as $i) {
        $this->sync->hello(['Authorization' => 'Bearer '.$wrong])->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key');
    }
    $this->sync->hello(['Authorization' => 'Bearer '.$wrong])->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');

    // Another key from the same IP: two more failures reach the IP limit, then even the right key waits.
    $this->sync->hello(['Authorization' => 'Bearer '.$other])->assertStatus(401);
    $this->sync->hello(['Authorization' => 'Bearer '.$other])->assertStatus(401);
    $this->sync->hello()->assertStatus(429);

    // Another IP is not affected, and a good call does not count.
    $this->withServerVariables(['REMOTE_ADDR' => '198.51.100.30']);
    $this->sync->hello()->assertOk();
    $this->sync->hello()->assertOk();

    // The window passes.
    $this->travel(16)->minutes();
    $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
    $this->sync->hello()->assertOk();
});

test('L10: sync keys keep working after an APP_KEY rotation and are stored again under the new key', function () {
    $old = (string) config('app.key');
    config(['app.key' => 'base64:'.base64_encode(random_bytes(32)), 'app.previous_keys' => [$old]]);

    $this->sync->hello()->assertOk();

    expect(SyncKey::withoutCompanyScope()->where('branch_id', $this->sync->leeds->id)->sole()->key_hash)->toBe(SyncKeySecret::hash($this->sync->leedsKey));

    config(['app.previous_keys' => []]);
    $this->sync->hello()->assertOk();
});

test('L4: a push never moves a row to another company, even if the id was taken between the read and the write', function () {
    $product = TillFixtures::sample('entities/Product.json');
    $this->sync->push([TillFixtures::envelope('Product', $product, 1)])->assertOk();
    $intruder = Company::factory()->create();
    $taken = false;

    // Another business takes the id right after the push has read the stored rows (a race).
    DB::listen(function (QueryExecuted $query) use (&$taken, $intruder, $product) {
        if (! $taken && str_starts_with($query->sql, 'select') && str_contains($query->sql, 'from "products"') && str_contains($query->sql, '"id" in')) {
            $taken = true;
            DB::table('products')->where('id', $product['id'])->update(['company_id' => $intruder->id]);
        }
    });

    $edit = [...$product, 'name' => 'Overwritten', 'rowVersion' => 2, 'updatedAt' => '2026-09-29T10:02:00Z'];
    $this->sync->push([TillFixtures::envelope('Product', $edit, 2, ['op' => 'U', 'at' => '2026-09-29T10:02:00Z'])])->assertServerError();

    expect($taken)->toBeTrue()
        ->and(DB::table('products')->where('id', $product['id'])->value('name'))->toBe($product['name']);
});
