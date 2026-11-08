<?php

use App\Domain\Salon\Calendar\FeedToken;
use App\Domain\Salon\Calendar\ICalWriter;
use App\Domain\Salon\Models\SalonCalendarFeed;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Salon\SalonFixtures as S;

/*
 * Salon W3b: the staff calendar feed (salon README §12). `GET /salon/ical/{branchId}/{userId}/{feedToken}.ics`,
 * built from pushed rows only: the person's own services (stylist or assistant), service + client first name, 7 days
 * back to 60 ahead, cancelled kept 7 days, no-shows out, nothing personal beyond the first name. "Now" is
 * 8 Nov 2026 10:00 UTC.
 */

beforeEach(function () {
    $this->travelTo(S::NOW);
    config(['salon.online' => true]);
    [$this->company, $this->leeds, $this->york] = S::business();
    $this->owner = S::member($this->company, CompanyRole::Owner);
    $this->priya = S::stylist($this->company, $this->leeds, 'Priya Patel');
    $this->tom = S::stylist($this->company, $this->leeds, 'Tom Reed');
    $this->cut = S::service($this->company, 'Cut and blow-dry');
    $this->issue = fn (string $userId, $shop = null) => $this->actingAs($this->owner)
        ->postJson('/app/salon/feeds', ['shopId' => ($shop ?? $this->leeds)->id, 'userId' => $userId])->assertOk()->json();
    $this->ics = fn (string $url) => $this->get(S::pathOf($url));
});

test('the feed holds only that person\'s services at that shop, with the service and the client\'s first name only', function () {
    $own = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-09 10:00:00');
    $assisting = S::booked($this->company, $this->leeds, $this->tom, $this->cut, '2026-11-09 11:00:00', ['client_name' => 'Ben Walker'], ['assistant_user_id' => $this->priya]);
    $tomOnly = S::booked($this->company, $this->leeds, $this->tom, $this->cut, '2026-11-09 12:00:00');
    S::stylist($this->company, $this->york, 'Priya Patel', ['user_id' => $this->priya]);
    $otherShop = S::booked($this->company, $this->york, $this->priya, $this->cut, '2026-11-09 13:00:00');

    $body = ($this->ics)(($this->issue)($this->priya)['url'])->assertOk()->getContent();

    expect(substr_count($body, 'BEGIN:VEVENT'))->toBe(2)
        ->and($body)->toContain("UID:{$own}@sspos\r\n")
        ->and($body)->toContain("UID:{$assisting}@sspos\r\n")
        ->and($body)->not->toContain($tomOnly)
        ->and($body)->not->toContain($otherShop)
        ->and($body)->toContain("SUMMARY:Cut and blow-dry – Sarah\r\n")
        ->and($body)->toContain("SUMMARY:Cut and blow-dry – Ben\r\n")
        ->and($body)->toContain("DESCRIPTION:You are assisting on this service.\r\n")
        ->and($body)->toContain("DTSTART:20261109T100000Z\r\nDTEND:20261109T110000Z\r\n")
        ->and($body)->toContain("STATUS:CONFIRMED\r\n")
        ->and($body)->toContain("LAST-MODIFIED:20261102T090000Z\r\n")
        ->and($body)->toContain("LOCATION:Leeds\r\n")
        ->and($body)->toContain("X-WR-CALNAME:Priya Patel – Leeds\r\n")
        ->and($body)->toStartWith("BEGIN:VCALENDAR\r\nVERSION:2.0\r\n")
        ->and($body)->toEndWith("END:VCALENDAR\r\n");

    // No surname, phone, e-mail, notes, price, deposit or health data.
    foreach (['Jones', 'Walker', '07700', 'example.com', 'latex', 'quiet', '45.00', '10.00', 'Allergic'] as $forbidden) {
        expect($body)->not->toContain($forbidden);
    }
});

test('the window is 7 days back to 60 days ahead', function () {
    $tooOld = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-10-31 09:00:00');
    $sixDaysBack = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-02 09:00:00');
    $fiftyNineAhead = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2027-01-06 09:00:00');
    $tooFar = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2027-01-08 09:00:00');

    $body = ($this->ics)(($this->issue)($this->priya)['url'])->assertOk()->getContent();

    expect($body)->toContain($sixDaysBack)->toContain($fiftyNineAhead)
        ->and($body)->not->toContain($tooOld)
        ->and($body)->not->toContain($tooFar);
});

test('cancelled appointments stay 7 days as STATUS:CANCELLED; no-shows and deleted rows are left out', function () {
    $recent = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-12 09:00:00', ['status' => 'cancelled', 'cancelled_at' => '2026-11-05 09:00:00']);
    $stale = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-13 09:00:00', ['status' => 'cancelled', 'cancelled_at' => '2026-10-31 09:00:00']);
    $noShow = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-07 09:00:00', ['status' => 'noShow']);
    $deleted = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-14 09:00:00', service: ['deleted_at' => '2026-11-06 09:00:00']);
    $completed = S::booked($this->company, $this->leeds, $this->priya, $this->cut, '2026-11-07 15:00:00', ['status' => 'completed']);

    $body = ($this->ics)(($this->issue)($this->priya)['url'])->assertOk()->getContent();
    $event = fn (string $uid) => (string) preg_replace('/^.*?(BEGIN:VEVENT\r\nUID:'.$uid.'@sspos.*?END:VEVENT).*$/s', '$1', $body);

    expect($body)->toContain($recent)->toContain($completed)
        ->and($event($recent))->toContain("STATUS:CANCELLED\r\n")
        ->and($event($completed))->toContain("STATUS:CONFIRMED\r\n")
        ->and($body)->not->toContain($stale)
        ->and($body)->not->toContain($noShow)
        ->and($body)->not->toContain($deleted);
});

test('text is escaped and long lines are folded at 75 octets without splitting a character', function () {
    $long = S::service($this->company, 'Balayage, toner; gloss\\treatment with a very long name that goes past seventy-five octets for sure');
    S::booked($this->company, $this->leeds, $this->priya, $long, '2026-11-10 09:00:00', ['client_name' => "Zoë-Élodie\nMartin"]);

    $body = ($this->ics)(($this->issue)($this->priya)['url'])->assertOk()->getContent();
    $lines = explode("\r\n", rtrim($body, "\r\n"));
    $unfolded = str_replace("\r\n ", '', $body);

    expect(collect($lines)->every(fn (string $line) => strlen($line) <= 75))->toBeTrue()
        ->and(collect($lines)->every(fn (string $line) => mb_check_encoding($line, 'UTF-8')))->toBeTrue()
        ->and(str_contains($body, "\n") && ! preg_match('/(?<!\r)\n/', $body))->toBeTrue()
        ->and($unfolded)->toContain('SUMMARY:Balayage\, toner\; gloss\\\\treatment with a very long name that goes past seventy-five octets for sure – Zoë-Élodie'."\r\n")
        ->and($unfolded)->not->toContain('Martin');
});

test('the reply is a private iCalendar cached for 5 minutes, with no cookie', function () {
    $response = ($this->ics)(($this->issue)($this->priya)['url'])->assertOk();

    expect($response->headers->get('Content-Type'))->toBe('text/calendar; charset=utf-8')
        ->and($response->headers->get('Cache-Control'))->toContain('max-age=300')->toContain('private')
        ->and($response->headers->getCookies())->toBe([]);
});

test('the token is random, stored only as its hash and last 4, and never in the audit log', function () {
    $reply = ($this->issue)($this->priya);
    $token = S::tokenOf($reply['url']);
    $feed = SalonCalendarFeed::withoutCompanyScope()->sole();

    expect(strlen($token))->toBe(43)
        ->and($feed->token_hash)->toBe(hash('sha256', $token))
        ->and($feed->token_last4)->toBe(substr($token, -4))
        ->and($reply['feed']['last4'])->toBe(substr($token, -4))
        ->and($reply['webcalUrl'])->toStartWith('webcal://')->toEndWith("/salon/ical/{$this->leeds->id}/{$this->priya}/{$token}.ics")
        ->and($reply['qrSvg'])->toStartWith('<svg')
        ->and(json_encode(DB::table('salon_calendar_feeds')->get()))->not->toContain($token)
        ->and(json_encode(AuditLog::query()->where('action', 'like', 'salon.%')->get()))->not->toContain($token)->not->toContain($feed->token_hash)
        ->and(AuditLog::query()->where('action', 'salon.calendar_feed_enabled')->count())->toBe(1)
        ->and(S::tokenOf(($this->issue)($this->tom)['url']))->not->toBe($token);
});

test('a new address stops the old one at once; turning the feed off gives 404', function () {
    $first = ($this->issue)($this->priya)['url'];
    ($this->ics)($first)->assertOk();

    $second = ($this->issue)($this->priya);
    expect($second['regenerated'])->toBeTrue()
        ->and(AuditLog::query()->where('action', 'salon.calendar_feed_regenerated')->count())->toBe(1);
    ($this->ics)($first)->assertNotFound();
    ($this->ics)($second['url'])->assertOk();

    $this->actingAs($this->owner)->deleteJson("/app/salon/feeds/{$second['feed']['id']}")->assertOk()->assertJsonPath('changed', true);
    ($this->ics)($second['url'])->assertNotFound();
    expect(SalonCalendarFeed::withoutCompanyScope()->sole())->token_hash->toBeNull()->enabled->toBeFalse()->revoked_at->not->toBeNull();

    // On again: a third address; the second stays dead.
    $third = ($this->issue)($this->priya)['url'];
    ($this->ics)($third)->assertOk();
    ($this->ics)($second['url'])->assertNotFound();
});

test('a wrong, malformed or someone else\'s token, another shop or stylist in the address, all 404 the same way', function () {
    $url = ($this->issue)($this->priya)['url'];
    $token = S::tokenOf($url);
    $tomUrl = ($this->issue)($this->tom)['url'];

    $this->get("/salon/ical/{$this->leeds->id}/{$this->priya}/".FeedToken::generate().'.ics')->assertNotFound()->assertSeeText('Not found');
    $this->get("/salon/ical/{$this->leeds->id}/{$this->priya}/short.ics")->assertNotFound();
    $this->get("/salon/ical/{$this->leeds->id}/{$this->tom}/{$token}.ics")->assertNotFound();
    $this->get("/salon/ical/{$this->york->id}/{$this->priya}/{$token}.ics")->assertNotFound();
    $this->get("/salon/ical/{$this->leeds->id}/{$this->priya}/".S::tokenOf($tomUrl).'.ics')->assertNotFound();
    ($this->ics)($url)->assertOk();
});

test('switch off, a shop that is no longer a salon, a closed shop or a cancelled business: 404', function () {
    $url = ($this->issue)($this->priya)['url'];

    config(['salon.online' => false]);
    ($this->ics)($url)->assertNotFound();
    config(['salon.online' => true]);
    ($this->ics)($url)->assertOk();

    $this->company->update(['business_type' => BusinessType::GroceryHalalButcher]);
    ($this->ics)($url)->assertNotFound();
    $this->company->update(['business_type' => BusinessType::Salon]);

    $this->leeds->update(['is_active' => false]);
    ($this->ics)($url)->assertNotFound();
    $this->leeds->update(['is_active' => true]);

    $this->company->update(['status' => CompanyStatus::Cancelled]);
    ($this->ics)($url)->assertNotFound();
});

test('the feed is rate limited per address', function () {
    config(['salon.ical_per_minute' => 3]);
    $url = ($this->issue)($this->priya)['url'];

    foreach (range(1, 3) as $i) {
        ($this->ics)($url)->assertOk();
    }
    ($this->ics)($url)->assertTooManyRequests();
    $this->get("/salon/ical/{$this->leeds->id}/{$this->priya}/".FeedToken::generate().'.ics')->assertTooManyRequests();
});

test('the last read is recorded for the owner, at most every 15 minutes', function () {
    $url = ($this->issue)($this->priya)['url'];
    ($this->ics)($url)->assertOk();
    expect(SalonCalendarFeed::withoutCompanyScope()->sole()->last_fetched_at?->toDateTimeString())->toBe('2026-11-08 10:00:00');

    $this->travel(10)->minutes();
    ($this->ics)($url)->assertOk();
    expect(SalonCalendarFeed::withoutCompanyScope()->sole()->last_fetched_at?->toDateTimeString())->toBe('2026-11-08 10:00:00');

    $this->travel(10)->minutes();
    ($this->ics)($url)->assertOk();
    expect(SalonCalendarFeed::withoutCompanyScope()->sole()->last_fetched_at?->toDateTimeString())->toBe('2026-11-08 10:20:00');
});

test('folding never splits a UTF-8 character and unfolds back to the same line', function () {
    $line = 'SUMMARY:'.str_repeat('é', 40).str_repeat('€', 30).'x';
    $folded = ICalWriter::fold($line);
    $parts = explode("\r\n", $folded);

    expect(count($parts))->toBeGreaterThan(2)
        ->and(collect($parts)->every(fn (string $p) => strlen($p) <= 75 && mb_check_encoding($p, 'UTF-8')))->toBeTrue()
        ->and(collect(array_slice($parts, 1))->every(fn (string $p) => str_starts_with($p, ' ')))->toBeTrue()
        ->and(str_replace("\r\n ", '', $folded))->toBe($line)
        ->and(ICalWriter::fold('SUMMARY:short'))->toBe('SUMMARY:short')
        ->and(ICalWriter::text("a\\b;c,d\r\ne\nf"))->toBe('a\\\\b\\;c\\,d\\ne\\nf');
});
