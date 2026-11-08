<?php

use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Support\SalonApiErrors;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Salon\SalonOnline;
use Tests\Feature\Sync\SalonRows;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * W3a, salon pack 2026-11-08 salon/README.md §3–§4: the five `sync/salon/*` calls. Every 2xx reply with a schema
 * (booking page, queue joins) and every error is also checked by ContractReplyGuard after each test.
 */

beforeEach(function () {
    $this->travelTo('2026-11-09 08:00:00');
    $this->s = new SalonOnline($this);
});

test('every request sample and our replies match the pack\'s schemas', function () {
    foreach ([
        'booking-page' => 'booking-page', 'booking-page-reply' => 'booking-page-reply', 'heartbeat' => 'heartbeat',
        'queue-joins-reply' => 'queue-joins-reply', 'queue-join-answer.joined' => 'queue-join-answer',
        'queue-join-answer.refused' => 'queue-join-answer', 'queue' => 'queue',
    ] as $sample => $schema) {
        expect(ContractSchema::salonErrors(SalonOnline::sample($sample), "salon/schemas/{$schema}.schema.json"))->toBe([], $sample);
    }

    $reply = $this->s->putPage()->assertOk();
    expect(ContractSchema::salonErrors($reply->json(), 'salon/schemas/booking-page-reply.schema.json'))->toBe([]);
});

test('switched off (the default): every call is 501 salon.not_available with Retry-After 3600, after the key check', function () {
    config(['salon_online.enabled' => false]);

    foreach ([
        $this->s->putPage(), $this->s->heartbeat(), $this->s->call('GET', 'queue-joins?max=20'),
        $this->s->call('POST', 'queue-joins/01K9S0000000000000000QJ001/answer', SalonOnline::sample('queue-join-answer.joined')),
        $this->s->call('PUT', 'queue', SalonOnline::sample('queue')),
    ] as $response) {
        $response->assertStatus(501)->assertJsonPath('code', SalonApiErrors::NOT_AVAILABLE)
            ->assertJsonPath('retryAfterSeconds', 3600)->assertHeader('Retry-After', '3600');
    }

    // An unknown key still learns nothing: 401 first. Nothing was stored.
    $this->s->putPage(headers: ['Authorization' => 'Bearer sspos_sync_'.str_repeat('x', 40)])->assertStatus(401);
    expect(SalonOnlineShop::withoutCompanyScope()->count())->toBe(0);
});

test('only a salon\'s main till may call: a grocery shop or a second till is 403 salon.not_a_salon', function () {
    // Bradford is a grocery.
    $this->s->putPage(bradford: true)->assertStatus(403)->assertJsonPath('code', SalonApiErrors::NOT_A_SALON);
    $this->s->heartbeat(bradford: true)->assertStatus(403);
    // Leeds' second till (not the main one).
    $this->s->heartbeat(['at' => '2026-11-09T08:00:00Z'])->assertOk();
    $this->s->call('PUT', 'heartbeat', SalonOnline::sample('heartbeat'), headers: ['X-SSPOS-Register-Id' => TillFixtures::TILL_2])
        ->assertStatus(403)->assertJsonPath('code', SalonApiErrors::NOT_A_SALON);

    expect(SalonOnlineShop::withoutCompanyScope()->where('branch_id', $this->s->sync->bradford->id)->exists())->toBeFalse();
});

test('the booking page is stored whole per shop, the same page again is unchanged, an older one never replaces it', function () {
    $first = $this->s->putPage()->assertOk()->assertJsonPath('result', 'stored');
    $shop = $this->s->shop();
    $urls = array_diff_key($first->json(), ['result' => 1]);

    expect($urls['bookingUrl'])->toBe(url('/book/kings-cuts'))
        ->and($urls['queueJoinUrl'])->toBe(url('/book/kings-cuts/queue'))
        ->and($urls['manageUrl'])->toBe(url('/book/kings-cuts').'/m/')
        ->and($shop->page['shopName'])->toBe('Kings Cuts')
        ->and($shop->page['rules']['holdMin'])->toBe(30)
        ->and($shop->page['services'][0]['options'][0]['price'])->toBe('18.00')
        ->and($shop->page)->not->toHaveKey('manageLink')
        ->and($shop->manage_key)->toBe('c2FtcGxlLWtleS1mb3ItdGhlLXBvcnRhbC1wYWNr')
        ->and($shop->manage_key_id)->toBe('521e36838909');

    // The key is encrypted at rest, never in the page column.
    $raw = (array) DB::table('salon_online_shops')->where('id', $shop->id)->first();
    expect($raw['manage_key'])->not->toContain('c2FtcGxl')->and($raw['page'])->not->toContain('c2FtcGxl');

    // The same page again (gzip or plain): unchanged, and the same addresses.
    $this->s->putPage()->assertOk()->assertJsonPath('result', 'unchanged')->assertJson($urls);
    $this->s->call('PUT', 'booking-page', SalonOnline::page())->assertOk()->assertJsonPath('result', 'unchanged');

    // Older: unchanged, kept. Newer: stored, replaces it (only the newest kept).
    $this->s->putPage(SalonOnline::page(['generatedAt' => '2026-11-09T07:00:00Z', 'shopName' => 'Old name']))->assertOk()->assertJsonPath('result', 'unchanged');
    $this->s->putPage(SalonOnline::page(['generatedAt' => '2026-11-09T08:15:00Z', 'rules.holdMin' => 45, 'manageLink' => null]))->assertOk()->assertJsonPath('result', 'stored')->assertJson($urls);

    $shop->refresh();
    expect($shop->page['rules']['holdMin'])->toBe(45)->and($shop->page['shopName'])->toBe('Kings Cuts')
        ->and($shop->manage_key)->toBe('c2FtcGxlLWtleS1mb3ItdGhlLXBvcnRhbC1wYWNr')
        ->and(SalonOnlineShop::withoutCompanyScope()->count())->toBe(1);
});

test('the public address is the shop\'s name, never a sequence, and another shop with the same name gets its own', function () {
    $this->s->putPage()->assertOk();
    // Another shop (here Bradford, once its till says it is a salon too) publishing under the same name.
    $this->s->sync->push([SalonRows::setting('vertical.salon', 'true', 1, branch: TillFixtures::BRADFORD)], bradford: true)->assertOk();
    $reply = $this->s->putPage(bradford: true)->assertOk();

    expect($reply->json('bookingUrl'))->toMatch('#/book/kings-cuts-[a-z2-9]{4}$#')
        ->and($reply->json('manageUrl'))->toBe($reply->json('bookingUrl').'/m/')
        ->and($this->s->putPage()->json('bookingUrl'))->toBe(url('/book/kings-cuts'));
});

test('a page over 8 MB unzipped is 413 salon.page_too_large, and nothing is stored', function () {
    $big = SalonOnline::page(['services.0.description' => str_repeat('a', 8 * 1024 * 1024 + 10)]);

    $this->s->putPage($big)->assertStatus(413)->assertJsonPath('code', SalonApiErrors::PAGE_TOO_LARGE);
    expect(SalonOnlineShop::withoutCompanyScope()->whereNotNull('page')->count())->toBe(0);
});

test('a page that breaks the schema is 422 request.invalid naming the member', function (array $set, string $key) {
    $this->s->putPage(SalonOnline::page($set))->assertStatus(422)->assertJsonPath('code', 'request.invalid')->assertJsonPath('rejectedKey', $key);
})->with([
    'holdMin not a number' => [['rules.holdMin' => 'soon'], 'rules.holdMin'],
    'a range out of shape' => [['availability.days.0.items.0.stylists.0.ranges.0' => '10-11'], 'availability.days[0].items[0].stylists[0].ranges[0]'],
    'no services' => [['services' => null], 'services'],
    'price not money' => [['services.0.options.0.price' => 'free'], 'services[0].options[0].price'],
]);

test('a broken body is 400 request.invalid', function () {
    $this->s->call('PUT', 'booking-page', 'not json')->assertStatus(400)->assertJsonPath('code', 'request.invalid');
    $this->s->call('PUT', 'booking-page', "\x1f\x8b broken", headers: ['Content-Encoding' => 'gzip'])->assertStatus(400);
});

test('the heartbeat records when we last heard the till, and an older one never puts older flags back', function () {
    $this->s->heartbeat()->assertOk()->assertExactJson(['result' => 'ok']);
    $shop = $this->s->shop();

    expect($shop->last_heard_at?->toIso8601ZuluString())->toBe('2026-11-09T08:00:00Z')
        ->and([$shop->accepting, $shop->queue_join_open, $shop->pending_requests, $shop->offline_after_min])->toBe([true, true, 1, 10]);

    $this->travelTo('2026-11-09 08:05:00');
    $this->s->heartbeat(['at' => '2026-11-09T07:59:00Z', 'accepting' => false])->assertOk();
    $shop->refresh();
    expect($shop->accepting)->toBeTrue()->and($shop->last_heard_at?->toIso8601ZuluString())->toBe('2026-11-09T08:05:00Z');

    // The last one when the shop switches both off.
    $this->s->heartbeat(['at' => '2026-11-09T08:05:00Z', 'accepting' => false, 'queueJoinOpen' => false])->assertOk();
    expect($this->s->shop()->accepting)->toBeFalse();

    $this->s->heartbeat(['pendingRequests' => -1])->assertStatus(422)->assertJsonPath('rejectedKey', 'pendingRequests');
});

test('the phone-queue push keeps the newest `at` and stores no names', function () {
    $this->s->call('PUT', 'queue', SalonOnline::sample('queue'))->assertOk()->assertExactJson(['result' => 'stored']);
    $this->s->call('PUT', 'queue', SalonOnline::sample('queue'))->assertOk()->assertExactJson(['result' => 'unchanged']);
    $this->s->call('PUT', 'queue', [...SalonOnline::sample('queue'), 'at' => '2026-11-09T08:00:00Z', 'waiting' => 9])->assertOk()->assertExactJson(['result' => 'unchanged']);
    $this->s->call('PUT', 'queue', [...SalonOnline::sample('queue'), 'at' => '2026-11-09T08:04:00Z', 'waiting' => 2])->assertOk()->assertExactJson(['result' => 'stored']);

    expect($this->s->shop()->queue)->toMatchArray(['waiting' => 2, 'isOpen' => true])
        ->and($this->s->shop()->queue['entries'][0])->toBe(SalonOnline::sample('queue')['entries'][0]);

    $this->s->call('PUT', 'queue', [...SalonOnline::sample('queue'), 'entries' => [['joinId' => 'x', 'ticket' => 'A1', 'status' => 'gone', 'position' => 0, 'minutesToWait' => null, 'estimatedStartAt' => null]]])
        ->assertStatus(422)->assertJsonPath('rejectedKey', 'entries[0].status');
});

test('salon calls have their own limit of 60 a minute per key (429 rate.limited), on top of the sync limit', function () {
    config(['salon_online.per_minute' => 6]);

    for ($i = 0; $i < 6; $i++) {
        $this->s->heartbeat()->assertOk();
    }

    $this->s->heartbeat()->assertStatus(429)->assertJsonPath('code', 'rate.limited')->assertHeader('Retry-After');
    // Push and pull are not held back by it.
    $this->s->sync->pull(0)->assertOk();
});

test('the switch never touches a grocery\'s sync', function () {
    $hello = fn () => $this->s->sync->hello(bradford: true)->assertOk()->json();
    $pull = fn () => $this->s->sync->pull(0, bradford: true)->assertOk()->json('changes');

    config(['salon_online.enabled' => false]);
    [$offHello, $offPull] = [$hello(), $pull()];
    config(['salon_online.enabled' => true]);
    $this->s->putPage()->assertOk();

    expect(array_diff_key($hello(), ['serverTime' => 1]))->toBe(array_diff_key($offHello, ['serverTime' => 1]))
        ->and($pull())->toBe($offPull);
});
