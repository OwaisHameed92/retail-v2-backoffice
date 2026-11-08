<?php

use App\Domain\Tenancy\Enums\BusinessType;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\Pharmacy\DmdFixtures as Dmd;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Sync\Till0185Rows as Rows;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ProposalSchema;

/*
 * W4, PORTAL-CHANGES-pharmacy.md item 12: `GET /api/v1/pharmacy/dmd/latest`, the weekly dm+d manifest for a pharmacy's
 * main till. Same sync key auth and §3 headers as sync/hello; behind PHARMACY_DMD (off by default). Every reply is also
 * checked by ContractReplyGuard against docs/contracts/proposals/pharmacy-dmd after each test.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-27 09:00:00');
    $this->f = new SyncApiFixtures($this);
    $this->latest = fn (array $headers = [], bool $bradford = false, ?SyncApiFixtures $f = null) => ($f ?? $this->f)->send('GET', '/api/v1/pharmacy/dmd/latest', $headers, null, $bradford);
    $this->pharmacy = fn () => $this->f->company->forceFill(['business_type' => BusinessType::Pharmacy])->save();
});

test('switched off (the default): 501 dmd.not_available with Retry-After for every shop, pharmacy or grocery', function () {
    expect(config('pharmacy.dmd.enabled'))->toBeFalse();

    ($this->latest)()->assertStatus(501)->assertJsonPath('code', 'dmd.not_available')
        ->assertJsonPath('retryAfterSeconds', 3600)->assertHeader('Retry-After', '3600')->assertHeader('X-SSPOS-Contract', '1');

    ($this->pharmacy)();
    Dmd::publish('2026-10-26');
    ($this->latest)()->assertStatus(501)->assertJsonPath('code', 'dmd.not_available');

    // An unknown key still learns nothing about the switch: 401 first.
    ($this->latest)(['Authorization' => 'Bearer sspos_sync_'.str_repeat('x', 40)])->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key');
});

test('switched on: a grocery\'s sync key is 403 dmd.not_pharmacy', function () {
    Dmd::on();
    Dmd::publish('2026-10-26');

    ($this->latest)()->assertStatus(403)->assertJsonPath('code', 'dmd.not_pharmacy')->assertJsonPath('rejectedKey', null);
    ($this->latest)([], bradford: true)->assertStatus(403)->assertJsonPath('code', 'dmd.not_pharmacy');
});

test('a pharmacy with no release yet gets 404 dmd.no_release (the till uses TRUD meanwhile)', function () {
    Dmd::on();
    ($this->pharmacy)();

    ($this->latest)()->assertStatus(404)->assertJsonPath('code', 'dmd.no_release');

    // A row whose zip is missing on disk is never named either.
    $release = Dmd::publish('2026-10-26');
    Storage::disk('local')->delete($release->path);
    ($this->latest)()->assertStatus(404)->assertJsonPath('code', 'dmd.no_release');
});

test('200: the newest release as the contract\'s manifest, with an https signed downloadUrl and no-cache', function () {
    Dmd::on();
    ($this->pharmacy)();
    Dmd::publish('2026-10-19');
    $release = Dmd::publish('2026-10-26');

    $response = ($this->latest)()->assertOk()->assertHeader('Cache-Control', 'no-cache, private');
    $body = $response->json();

    expect(array_keys($body))->toBe(['releaseId', 'name', 'releaseDate', 'week', 'archiveFileName', 'downloadUrl', 'sizeBytes', 'sha256', 'publishedAt'])
        ->and($body)->toMatchArray([
            'releaseId' => 'nhsbsa_dmd_10.3.0_20261026000001',
            'name' => 'Release 10.3.0',
            'releaseDate' => '2026-10-26',
            'week' => '2026-W44',
            'archiveFileName' => 'nhsbsa_dmd_10.3.0_20261026000001.zip',
            'sizeBytes' => $release->size_bytes,
            'sha256' => strtoupper($release->sha256),
            'publishedAt' => '2026-10-26T07:12:00Z',
        ])
        ->and($body['downloadUrl'])->toStartWith('https://portal.test/api/v1/pharmacy/dmd/packages/nhsbsa_dmd_10.3.0_20261026000001.zip?expires=')
        ->and($body['downloadUrl'])->toContain('&signature=')
        ->and(ProposalSchema::errorsIn('proposals/pharmacy-dmd', $body, 'schemas/dmd-manifest-reply.schema.json'))->toBe([]);

    // The link lives at least 24 h (48 h by default) from now.
    parse_str((string) parse_url($body['downloadUrl'], PHP_URL_QUERY), $query);
    expect((int) $query['expires'])->toBe(now()->addHours(48)->getTimestamp());
});

test('the till knows a pharmacy by a setting its till pushed for that shop, not another shop of the business', function () {
    Dmd::on();
    Dmd::publish('2026-10-26');

    // Leeds' till sends setup.business_type = Pharmacy for Leeds (branch scope).
    $this->f->push([Rows::setting('setup.business_type', 'Pharmacy', 1)])->assertOk();

    ($this->latest)()->assertOk()->assertJsonPath('releaseDate', '2026-10-26');
    ($this->latest)([], bradford: true)->assertStatus(403)->assertJsonPath('code', 'dmd.not_pharmacy');
});

test('the sync key and §3 headers are required, exactly as on sync/hello', function () {
    Dmd::on();
    ($this->pharmacy)();
    Dmd::publish('2026-10-26');

    ($this->latest)(['Authorization' => null])->assertStatus(401)->assertJsonPath('code', 'auth.invalid_key');
    ($this->latest)(['X-SSPOS-Branch-Id' => TillFixtures::BRADFORD])->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch');
    ($this->latest)(['X-SSPOS-Contract' => '2'])->assertStatus(409)->assertJsonPath('code', 'contract.unsupported');
    ($this->latest)(['X-SSPOS-App-Version' => null])->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    ($this->latest)(['X-SSPOS-Register-Id' => null])->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    ($this->latest)(['X-SSPOS-Store-Protocol' => null])->assertOk();
});

test('another business\'s key reaches only its own shop: never this one\'s manifest, and its own grocery shop is 403', function () {
    Dmd::on();
    ($this->pharmacy)();
    Dmd::publish('2026-10-26');
    $other = new SyncApiFixtures($this, mapTillIds: false);

    // The other business's Leeds key with OUR till ids → wrong branch.
    ($this->latest)(['Authorization' => 'Bearer '.$other->leedsKey])->assertStatus(403)->assertJsonPath('code', 'auth.wrong_branch');

    // Its own shop (its till sends our-side ids for itself): authenticated, but not a pharmacy.
    $own = ['X-SSPOS-Company-Id' => $other->company->id, 'X-SSPOS-Branch-Id' => $other->leeds->id, 'X-SSPOS-Register-Id' => $other->tills[TillFixtures::TILL_1]->id];
    ($this->latest)($own, f: $other)->assertStatus(403)->assertJsonPath('code', 'dmd.not_pharmacy');

    // A Pharmacy setting pushed by OUR shop does not make the other business a pharmacy.
    $this->f->push([Rows::setting('vertical.pharmacy', 'true', 1)])->assertOk();
    ($this->latest)($own, f: $other)->assertStatus(403)->assertJsonPath('code', 'dmd.not_pharmacy');
});

test('rate limited per sync key: over pharmacy.dmd.per_minute → 429 rate.limited with Retry-After', function () {
    Dmd::on();
    config(['pharmacy.dmd.per_minute' => 3]);
    ($this->pharmacy)();
    Dmd::publish('2026-10-26');

    foreach (range(1, 3) as $i) {
        ($this->latest)()->assertOk();
    }

    $limited = ($this->latest)()->assertStatus(429)->assertJsonPath('code', 'rate.limited');
    expect((int) $limited->headers->get('Retry-After'))->toBeGreaterThan(0);

    // Bradford's key (same pharmacy business) has its own bucket, and push / pull of the limited key are not affected.
    ($this->latest)([], bradford: true)->assertOk();
    $this->f->hello()->assertOk();
    $this->f->pull(0)->assertOk();
});

test('a grocery\'s push and pull are unchanged with the switch off and on', function () {
    foreach ([false, true] as $on) {
        config(['pharmacy.dmd.enabled' => $on, 'pharmacy.dmd.trud_key' => $on ? Dmd::KEY : null]);

        $this->f->hello()->assertOk();
        $this->f->push([Rows::setting('till.show_all_chip', $on ? 'true' : 'false', $on ? 2 : 1)])->assertOk()->assertJsonPath('acknowledgedSeq', $on ? 2 : 1);
        $this->f->pull(0)->assertOk();
        ($this->latest)()->assertStatus($on ? 403 : 501);
    }
});
