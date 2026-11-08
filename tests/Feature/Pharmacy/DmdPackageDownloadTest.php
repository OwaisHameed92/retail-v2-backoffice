<?php

use App\Domain\Pharmacy\Support\DmdPackageLink;
use App\Domain\Tenancy\Enums\BusinessType;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\Feature\Pharmacy\DmdFixtures as Dmd;
use Tests\Feature\Sync\SyncApiFixtures;

/*
 * W4: `GET {downloadUrl}`, the NHSBSA zip itself. A signed link the till fetches with no headers (a till sends the
 * Bearer again on our own host; it is not needed), valid ≥ 24 h (48 h), streamed unchanged and immutable.
 */

beforeEach(function () {
    Storage::fake('local');
    $this->travelTo('2026-10-27 09:00:00');
    Dmd::on();
    $this->f = new SyncApiFixtures($this);
    $this->f->company->forceFill(['business_type' => BusinessType::Pharmacy])->save();
    $this->bytes = Dmd::zip('2026-10-26');
    $this->release = Dmd::publish('2026-10-26', $this->bytes);
    // The manifest's link, as the till receives it.
    $this->link = fn () => (string) $this->f->send('GET', '/api/v1/pharmacy/dmd/latest')->assertOk()->json('downloadUrl');
    // GET with no headers at all (the till on another host); only the path and query of the https link are used.
    $this->fetch = fn (string $url, array $server = []): TestResponse => $this->call('GET', (string) parse_url($url, PHP_URL_PATH).'?'.parse_url($url, PHP_URL_QUERY), [], [], [], $server);
});

test('the signed link streams the stored zip unchanged, whole, immutable, with no sync headers', function () {
    $response = ($this->fetch)(($this->link)())->assertOk()
        ->assertHeader('Content-Type', 'application/zip')
        ->assertHeader('Content-Length', (string) strlen($this->bytes))
        ->assertHeader('Content-Disposition', 'attachment; filename="nhsbsa_dmd_10.3.0_20261026000001.zip"')
        ->assertHeader('ETag', '"'.hash('sha256', $this->bytes).'"');

    $cache = (string) $response->headers->get('Cache-Control');
    $body = $response->streamedContent();

    expect($cache)->toContain('public')->toContain('max-age=31536000')->toContain('immutable')
        ->and($body)->toBe($this->bytes)
        ->and(strtoupper(hash('sha256', $body)))->toBe($this->f->send('GET', '/api/v1/pharmacy/dmd/latest')->json('sha256'));

    // The till sends its Bearer again on the portal's own host: still fine.
    ($this->fetch)(($this->link)(), ['HTTP_AUTHORIZATION' => 'Bearer '.$this->f->leedsKey])->assertOk();
});

test('the link works for 48 hours (never less than 24) and is 403 dmd.link_invalid once expired', function () {
    $url = ($this->link)();

    $this->travel(47)->hours();
    ($this->fetch)($url)->assertOk();

    $this->travel(2)->hours();
    ($this->fetch)($url)->assertStatus(403)->assertJsonPath('code', 'dmd.link_invalid');

    // A config below 24 h is raised to 24 h.
    config(['pharmacy.dmd.link_hours' => 1]);
    $short = DmdPackageLink::for($this->release);
    $this->travel(23)->hours();
    ($this->fetch)($short)->assertOk();
});

test('a wrong, missing or altered signature is 403 dmd.link_invalid; no key or other member is taken from the query', function () {
    $url = ($this->link)();
    $path = (string) parse_url($url, PHP_URL_PATH);
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);

    ($this->fetch)($path.'?expires='.$query['expires'].'&signature='.str_repeat('0', 64))->assertStatus(403)->assertJsonPath('code', 'dmd.link_invalid');
    $this->call('GET', $path)->assertStatus(403)->assertJsonPath('code', 'dmd.link_invalid');
    ($this->fetch)($path.'?expires='.($query['expires'] + 86400).'&signature='.$query['signature'])->assertStatus(403);
    ($this->fetch)($url.'&key='.$this->f->leedsKey)->assertStatus(403)->assertJsonPath('code', 'dmd.link_invalid');

    // Another release's name with this signature.
    $other = str_replace('20261026', '20261019', $path);
    ($this->fetch)($other.'?'.parse_url($url, PHP_URL_QUERY))->assertStatus(403);
});

test('a release pruned since the manifest, or a name never held, is 404 dmd.no_release', function () {
    $url = ($this->link)();
    Storage::disk('local')->deleteDirectory('pharmacy/dmd');

    ($this->fetch)($url)->assertStatus(404)->assertJsonPath('code', 'dmd.no_release');

    $this->release->delete();
    ($this->fetch)($url)->assertStatus(404)->assertJsonPath('code', 'dmd.no_release');

    // Anything but a plain zip name never reaches the controller.
    $this->call('GET', '/api/v1/pharmacy/dmd/packages/..%2F..%2F.env')->assertNotFound();
});

test('switched off, the zip link is 501 dmd.not_available too', function () {
    $url = ($this->link)();
    config(['pharmacy.dmd.enabled' => false]);

    ($this->fetch)($url)->assertStatus(501)->assertJsonPath('code', 'dmd.not_available')->assertHeader('Retry-After', '3600');
});

test('zip downloads are rate limited per IP', function () {
    config(['pharmacy.dmd.downloads_per_minute' => 2]);
    $url = ($this->link)();

    ($this->fetch)($url)->assertOk();
    ($this->fetch)($url)->assertOk();
    ($this->fetch)($url)->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');
    ($this->fetch)($url, ['REMOTE_ADDR' => '10.0.0.9'])->assertOk();
});
