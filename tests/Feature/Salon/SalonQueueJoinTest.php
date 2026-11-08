<?php

use App\Domain\Salon\Models\SalonQueueJoin;
use App\Domain\Salon\Support\SalonApiErrors;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Salon\SalonOnline;
use Tests\Feature\Sync\SalonRows;
use Tests\Feature\TillData\TillFixtures;

/*
 * W3a, salon pack README §4.3–§4.5, §6 (checklist 8): queue join from a phone. The client joins on the door QR's page;
 * the shop's main till lists the join (`GET sync/salon/queue-joins`), answers it, and pushes the phone joiners'
 * places (`PUT sync/salon/queue`), which the "my position" page shows. Never another client's name or phone.
 */

beforeEach(function () {
    $this->travelTo('2026-11-09 08:00:00');
    $this->s = new SalonOnline($this);
    $this->s->open();
    $this->join = fn (array $data = []) => $this->s->postJson($this->s->url('queue'), ['firstName' => 'Sam', 'phone' => '07700 900222', 'services' => [SalonOnline::CUT], 'stylist' => 'any', 'note' => '', ...$data]);
    $this->listed = fn (string $query = '') => $this->s->call('GET', 'queue-joins'.$query)->assertOk()->json();
    $this->answer = fn (string $joinId, array $set = []) => $this->s->call('POST', "queue-joins/{$joinId}/answer", [...SalonOnline::sample('queue-join-answer.joined'), 'joinId' => $joinId, ...$set]);
    $this->position = fn (string $joinId) => $this->get($this->s->url("queue/{$joinId}"))->assertOk();
});

test('join → listed to the till → answered → my position → called', function () {
    $this->get($this->s->url('queue'))->assertOk()->assertInertia(fn (Assert $page) => $page->component('salon-public/queue')
        ->where('open', true)->where('shop.name', 'Kings Cuts')->has('services', 3)->has('stylists', 2));

    $redirect = ($this->join)()->assertCreated()->json('redirect');
    $join = SalonQueueJoin::withoutCompanyScope()->sole();

    expect($redirect)->toBe(url('/book/kings-cuts/queue').'/'.$join->id)
        ->and($join->id)->toMatch('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/');

    // The till collects it, exactly as the pack's sample.
    $listed = ($this->listed)('?max=20');
    expect($listed)->toBe(['joins' => [[
        'joinId' => $join->id, 'firstName' => 'Sam', 'phone' => '07700 900222', 'serviceIds' => [SalonOnline::CUT],
        'stylistUserId' => null, 'note' => '', 'joinedAt' => '2026-11-09T08:00:00Z',
    ]], 'hasMore' => false]);

    ($this->position)($join->id)->assertInertia(fn (Assert $page) => $page->component('salon-public/queue-position')->where('position.state', 'sent'));

    // Answered: no longer listed; the page shows the ticket.
    ($this->answer)($join->id)->assertOk()->assertExactJson(['result' => 'stored']);
    expect(($this->listed)()['joins'])->toBe([]);
    ($this->position)($join->id)->assertInertia(fn (Assert $page) => $page->where('position.state', 'waiting')->where('position.ticket', 'A004')->where('position.position', 3));

    // The till's queue push moves them, then calls them.
    $entry = ['joinId' => $join->id, 'ticket' => 'A004', 'status' => 'waiting', 'position' => 1, 'minutesToWait' => 5, 'estimatedStartAt' => '2026-11-09T08:10:00Z'];
    $this->s->call('PUT', 'queue', [...SalonOnline::sample('queue'), 'at' => '2026-11-09T08:05:00Z', 'entries' => [$entry]])->assertOk();
    ($this->position)($join->id)->assertInertia(fn (Assert $page) => $page->where('position.state', 'waiting')->where('position.position', 1)->where('position.minutesToWait', 5));

    $this->s->call('PUT', 'queue', [...SalonOnline::sample('queue'), 'at' => '2026-11-09T08:09:00Z', 'entries' => [[...$entry, 'status' => 'called', 'position' => 0]]])->assertOk();
    ($this->position)($join->id)->assertInertia(fn (Assert $page) => $page->where('position.state', 'called'));
});

test('the same join twice is one ticket: a double tap is one join, and an answer sent again is 200', function () {
    $key = 'double-tap-key-1';
    $first = ($this->join)(['clientKey' => $key])->assertCreated()->json('redirect');
    expect(($this->join)(['clientKey' => $key])->assertCreated()->json('redirect'))->toBe($first)
        ->and(SalonQueueJoin::withoutCompanyScope()->count())->toBe(1);

    $id = SalonQueueJoin::withoutCompanyScope()->sole()->id;
    ($this->answer)($id)->assertOk();
    ($this->answer)($id)->assertOk();
    expect(SalonQueueJoin::withoutCompanyScope()->sole()->answer['ticket'])->toBe('A004');
});

test('refused: the till\'s message is shown as it wrote it', function () {
    ($this->join)()->assertCreated();
    $id = SalonQueueJoin::withoutCompanyScope()->sole()->id;

    ($this->answer)($id, SalonOnline::sample('queue-join-answer.refused'))->assertStatus(422)->assertJsonPath('rejectedKey', 'joinId');
    ($this->answer)($id, [...SalonOnline::sample('queue-join-answer.refused'), 'joinId' => $id])->assertOk();

    ($this->position)($id)->assertInertia(fn (Assert $page) => $page->where('position.state', 'refused')
        ->where('position.message', 'The queue is paused just now. Please try again in a few minutes or ask at the desk.'));
});

test('a join nobody answered within 15 minutes is dropped: no longer listed, the client asks at the desk', function () {
    ($this->join)()->assertCreated();
    $id = SalonQueueJoin::withoutCompanyScope()->sole()->id;

    $this->travelTo('2026-11-09 08:14:59');
    expect(($this->listed)()['joins'])->toHaveCount(1);

    $this->travelTo('2026-11-09 08:15:00');
    $this->s->heartbeat(['at' => '2026-11-09T08:15:00Z'])->assertOk();
    expect(($this->listed)()['joins'])->toBe([]);
    ($this->position)($id)->assertInertia(fn (Assert $page) => $page->where('position.state', 'dropped'));
    ($this->answer)($id)->assertStatus(404)->assertJsonPath('code', SalonApiErrors::JOIN_NOT_FOUND)->assertJsonPath('rejectedKey', $id);
});

test('joins are listed oldest first, at most `max`, with hasMore; an unknown or another shop\'s join is 404', function () {
    foreach (['Amy', 'Ben', 'Cal'] as $i => $name) {
        $this->travelTo('2026-11-09 08:0'.$i.':00');
        $this->s->heartbeat(['at' => now('UTC')->toIso8601ZuluString()])->assertOk();
        ($this->join)(['firstName' => $name])->assertCreated();
    }

    $two = ($this->listed)('?max=2');
    expect(array_column($two['joins'], 'firstName'))->toBe(['Amy', 'Ben'])->and($two['hasMore'])->toBeTrue()
        ->and(array_column(($this->listed)('?max=x')['joins'], 'firstName'))->toBe(['Amy', 'Ben', 'Cal']);

    ($this->answer)('01K9S0000000000000000QJ999')->assertStatus(404)->assertJsonPath('code', SalonApiErrors::JOIN_NOT_FOUND);

    // Bradford becomes a salon too: it never sees or answers Leeds' joins.
    $this->s->sync->push([SalonRows::setting('vertical.salon', 'true', 1, branch: TillFixtures::BRADFORD)], bradford: true)->assertOk();
    expect($this->s->call('GET', 'queue-joins', bradford: true)->assertOk()->json('joins'))->toBe([]);
    $this->s->call('POST', 'queue-joins/'.$two['joins'][0]['joinId'].'/answer', [...SalonOnline::sample('queue-join-answer.joined'), 'joinId' => $two['joins'][0]['joinId']], bradford: true)->assertStatus(404);
});

test('the page checks the desk\'s rules: first name, phone, at most 10 services from the menu, a listed stylist, note', function (array $data, string $field) {
    ($this->join)($data)->assertStatus(422)->assertJsonValidationErrors($field);
    expect(SalonQueueJoin::withoutCompanyScope()->count())->toBe(0);
})->with([
    'no first name' => [['firstName' => ''], 'firstName'],
    'first name over 60' => [['firstName' => str_repeat('a', 61)], 'firstName'],
    'phone with letters' => [['phone' => '0770 CALL ME'], 'phone'],
    'phone over 30' => [['phone' => str_repeat('1', 31)], 'phone'],
    'eleven services' => [['services' => array_map(fn ($i) => "S{$i}", range(1, 11))], 'services'],
    'a service not on the menu' => [['services' => ['01K9S0000000000000000SV999']], 'services'],
    'a stylist not listed' => [['stylist' => '01K9S0000000000000000ST999'], 'stylist'],
    'note over 200' => [['note' => str_repeat('a', 201)], 'note'],
]);

test('offline or paused: "please ask at the desk", and no join is taken', function () {
    $this->travelTo('2026-11-09 08:11:00');   // no heartbeat for 11 minutes
    $this->get($this->s->url('queue'))->assertOk()->assertInertia(fn (Assert $page) => $page->where('open', false));
    ($this->join)()->assertStatus(422)->assertJsonPath('errors.firstName.0', 'The queue is not taking phone joins just now — please ask at the desk.');

    $this->s->heartbeat(['at' => '2026-11-09T08:11:00Z'])->assertOk();
    $this->s->call('PUT', 'queue', [...SalonOnline::sample('queue'), 'at' => '2026-11-09T08:11:00Z', 'isOpen' => false])->assertOk();
    ($this->join)()->assertStatus(422);

    $this->s->call('PUT', 'queue', [...SalonOnline::sample('queue'), 'at' => '2026-11-09T08:12:00Z'])->assertOk();
    $this->s->heartbeat(['at' => '2026-11-09T08:12:00Z', 'queueJoinOpen' => false])->assertOk();
    ($this->join)()->assertStatus(422);
    expect(SalonQueueJoin::withoutCompanyScope()->count())->toBe(0);
});

test('my position never shows another client\'s name or phone', function () {
    ($this->join)(['firstName' => 'Sam', 'phone' => '07700 900222'])->assertCreated();
    ($this->join)(['firstName' => 'Priya', 'phone' => '07700 900333'])->assertCreated();
    $sam = SalonQueueJoin::withoutCompanyScope()->where('first_name', 'Sam')->sole();

    $body = (string) ($this->position)($sam->id)->getContent();
    expect($body)->not->toContain('Priya')->not->toContain('900333')->not->toContain('900222');
});
