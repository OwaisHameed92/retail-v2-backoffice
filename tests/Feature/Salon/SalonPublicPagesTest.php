<?php

use App\Domain\Salon\Models\SalonQueueJoin;
use App\Domain\Salon\Queries\OnlineBookingSummary;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Illuminate\Support\Facades\Log;
use Tests\Feature\Salon\SalonOnline as S;
use Tests\Feature\Sync\SalonRows;

/*
 * W3a: the public salon pages as a whole. SALON_ONLINE off → every page 404; `embed=1` lets any site frame the booking
 * page only; public writes are rate limited; nothing about a client, a token or a key reaches the log.
 */

beforeEach(function () {
    $this->travelTo('2026-11-09 08:00:00');
    $this->s = new S($this);
    $this->s->open();
    $this->book = fn (array $set = []) => $this->s->postJson($this->s->url(), [
        'options' => [S::CUT], 'addOns' => [], 'stylist' => 'any', 'date' => '2026-11-09', 'time' => '14:00',
        'firstName' => 'Zoe', 'lastName' => 'Patel', 'phone' => '07700 900123', 'email' => 'zoe@example.co.uk', 'answers' => ['No'], 'terms' => true, ...$set,
    ]);
});

test('switched off: every public page and write is a 404, and nothing is written', function () {
    $redirect = ($this->book)()->assertCreated()->json('redirect');
    config(['salon.online' => false]);

    foreach ([$this->s->url(), $this->s->url('times'), $redirect, $this->s->url('queue'), $this->s->url('m/01K9S0000000000000000AP001.'.str_repeat('a', 22))] as $url) {
        $this->get($url)->assertNotFound();
    }

    ($this->book)(['time' => '15:00'])->assertNotFound();
    $this->s->postJson($this->s->url('queue'), ['firstName' => 'Sam'])->assertNotFound();
    $this->postJson($redirect.'/withdraw')->assertNotFound();
    expect(SalonOnlineBooking::withoutCompanyScope()->count())->toBe(1)->and(SalonQueueJoin::withoutCompanyScope()->count())->toBe(0);
});

test('an unknown address, a grocery or a shop with no page yet is a 404', function () {
    $this->get('/book/no-such-shop')->assertNotFound();
    $this->get('/book/'.$this->s->shop()->slug.'/r/01K9S0000000000000000RQ999')->assertNotFound();
    $this->get('/book/'.$this->s->shop()->slug.'/queue/01K9S0000000000000000QJ999')->assertNotFound();

    // Leeds stops being a salon (its till's wizard changed the shop type): its page goes.
    $this->s->push([SalonRows::setting('vertical.salon', 'false', 5)])->assertOk();
    $this->get($this->s->url())->assertNotFound();
});

test('embed=1 lets any site frame the booking page (and its "request sent" page) only', function () {
    $framed = $this->get($this->s->url().'?src=widget&embed=1')->assertOk();
    expect($framed->headers->has('X-Frame-Options'))->toBeFalse()
        ->and((string) $framed->headers->get('Content-Security-Policy'))->toContain('frame-ancestors *');
    $framed->assertInertia(fn ($page) => $page->where('embed', true)->where('src', 'widget'));

    $redirect = $this->s->postJson($this->s->url().'?embed=1', [
        'options' => [S::CUT], 'stylist' => 'any', 'date' => '2026-11-09', 'time' => '14:00', 'firstName' => 'Zoe', 'lastName' => 'Patel',
        'phone' => '07700 900123', 'email' => 'zoe@example.co.uk', 'terms' => true,
    ])->assertCreated()->json('redirect');
    expect($redirect)->toEndWith('?embed=1');
    expect($this->get($redirect)->headers->has('X-Frame-Options'))->toBeFalse();

    // Without embed=1, and on every other page (queue join, manage link, the portal), no other site may frame it.
    foreach ([$this->s->url(), $this->s->url('queue').'?embed=1', $this->s->url('m/01K9S0000000000000000AP001.'.str_repeat('a', 22)).'?embed=1', '/login?embed=1'] as $url) {
        $response = $this->get($url);
        expect($response->headers->get('X-Frame-Options'))->toBe('SAMEORIGIN', $url)
            ->and((string) $response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'self'");
    }
});

test('public writes are rate limited per visitor and shop', function () {
    config(['salon.public.writes_per_minute' => 2]);

    ($this->book)(['time' => '14:00'])->assertCreated();
    ($this->book)(['time' => '15:00'])->assertCreated();
    ($this->book)(['time' => '16:00'])->assertStatus(429);
    $this->s->postJson($this->s->url('queue'), ['firstName' => 'Sam'])->assertStatus(429);
    expect(SalonOnlineBooking::withoutCompanyScope()->count())->toBe(2);
});

test('no client details, token or key are written to the log', function () {
    $lines = [];
    Log::listen(function ($message) use (&$lines) {
        $lines[] = $message->message.' '.json_encode($message->context);
    });

    ($this->book)()->assertCreated();
    ($this->book)(['time' => '09:00'])->assertStatus(422);
    $this->s->putPage(S::page(['rules.holdMin' => 'x']))->assertStatus(422);
    $this->get($this->s->url('m/01K9S0000000000000000AP001.'.str_repeat('a', 22)))->assertNotFound();

    $all = implode("\n", $lines);
    expect($all)->not->toContain('Zoe')->not->toContain('900123')->not->toContain('zoe@example')
        ->not->toContain('c2FtcGxl')->not->toContain(str_repeat('a', 22));
});

test('the pack\'s checklist "before a real salon is connected", as far as the portal can prove it', function () {
    // 1. 501 until switched on (SalonOnlineApiTest), push and pull carry on.
    config(['salon.online' => false]);
    $this->s->heartbeat()->assertStatus(501);
    $this->s->sync->pull(0)->assertOk();
    config(['salon.online' => true]);

    // 2. Page stored, addresses returned; the same page again → unchanged.
    $this->s->putPage()->assertOk()->assertJsonPath('result', 'unchanged');

    // 3–6, 7, 8: SalonBookingFlowTest, SalonManageLinkTest, SalonQueueJoinTest. 9. A grocery's key is refused.
    $this->s->heartbeat(bradford: true)->assertStatus(403)->assertJsonPath('code', 'salon.not_a_salon');
    $pulled = array_column($this->s->sync->pull(0, bradford: true)->assertOk()->json('changes'), 'entity');
    expect(array_filter($pulled, fn (string $e) => str_starts_with($e, 'Salon')))->toBe([]);
});

test('the tenant Salon page\'s "Online booking" card is filled from the stored page and heartbeat', function () {
    $summary = fn () => app(CurrentCompany::class)->runAs($this->s->sync->company, fn () => OnlineBookingSummary::for([$this->s->sync->bradford->id, $this->s->sync->leeds->id], now('UTC')->toImmutable()));

    expect($summary())->toBe([
        'accepting' => true, 'lastHeardAt' => '2026-11-09T08:00:00Z', 'bookingUrl' => url('/book/kings-cuts'),
        'queueJoinUrl' => url('/book/kings-cuts').'/queue', 'shopName' => 'Kings Cuts', 'state' => 'open',
    ]);

    $this->travelTo('2026-11-09 08:30:00');
    expect($summary()['state'])->toBe('offline')->and($summary()['accepting'])->toBeTrue();

    $this->s->heartbeat(['at' => '2026-11-09T08:30:00Z', 'accepting' => false])->assertOk();
    expect($summary()['accepting'])->toBeFalse()
        ->and(app(CurrentCompany::class)->runAs($this->s->sync->company, fn () => OnlineBookingSummary::for([$this->s->sync->bradford->id], now('UTC')->toImmutable())))->toBeNull();
});
