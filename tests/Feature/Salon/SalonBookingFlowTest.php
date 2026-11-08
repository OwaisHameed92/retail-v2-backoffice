<?php

use App\Domain\Salon\Models\SalonOnlineRequestSource;
use App\Domain\TillData\Enums\SalonOnlineBookingStatus;
use App\Domain\TillData\Models\Customer;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Salon\SalonOnline as S;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SalonRows;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * W3a, salon pack README §2, §5, §6, §9, §11 (checklist 2–6): the public booking page, a request written as a
 * hub-owned SalonOnlineBooking row for the shop, pulled by its till, answered (accepted with its appointment, or
 * declined with a reason), and shown to the client only then. The offline rule ("request only") and a closed page.
 */

beforeEach(function () {
    $this->travelTo('2026-11-09 08:00:00');
    $this->s = new S($this);
    $this->shop = $this->s->open();
    $this->form = fn (array $set = []) => [
        'options' => [S::CUT], 'addOns' => [S::BEARD], 'stylist' => S::BEN, 'date' => '2026-11-09', 'time' => '14:00',
        'firstName' => 'Zoe', 'lastName' => 'Patel', 'phone' => '07700 900123', 'email' => 'zoe@example.co.uk',
        'notes' => 'Short back and sides please', 'answers' => ['No'], 'terms' => true, ...$set,
    ];
    $this->book = fn (array $set = []) => $this->s->postJson($this->s->url(), ($this->form)($set));
    // The request a redirect names (or the only one).
    $this->request = fn (?string $redirect = null) => $redirect === null
        ? SalonOnlineBooking::withoutCompanyScope()->sole()
        : SalonOnlineBooking::withoutCompanyScope()->findOrFail(basename(strtok($redirect, '?')));
    $this->pulled = function (bool $bradford = false): array {
        $reply = $this->s->sync->pull(0, bradford: $bradford)->assertOk();
        expect(ContractSchema::pullErrors($reply->json()))->toBe([]);

        return array_values(array_filter(Pull::changes($reply), fn (array $c) => in_array($c['entity'], ['Customer', 'SalonOnlineBooking'], true)));
    };
    $this->answer = fn (string $status, array $set = [], int $seq = 20) => TillFixtures::envelope('SalonOnlineBooking', [
        ...S::sample('online-request.accepted'),
        ...array_intersect_key(($this->pulled)()[1]['payload'], array_flip(['id', 'reference', 'requestedStartsAt', 'requestedUserId', 'servicesJson', 'clientFirstName', 'clientLastName', 'clientPhone', 'clientEmail', 'customerId', 'notes', 'answersJson', 'termsAcceptedAt', 'expiresAt', 'createdAt', 'marketingSms', 'marketingEmail', 'marketingWhatsApp'])),
        'status' => $status, 'decidedAt' => now('UTC')->toIso8601ZuluString(), 'updatedAt' => now('UTC')->toIso8601ZuluString(), ...$set,
    ], $seq, ['op' => 'U']);
});

test('the page shows the till\'s page in the shop\'s name: services, options, add-ons, stylists, rules', function () {
    $this->get($this->s->url().'?src=instagram&utm_campaign=x&fbclid=y')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('salon-public/book')
        ->where('shop.name', 'Kings Cuts')->where('status', 'open')->where('embed', false)->where('src', 'instagram')
        ->has('services', 2)->where('services.1.options.1.label', 'Long')->where('services.0.addOns.0.name', 'Beard trim')
        ->where('services.0.options.0.byStylist.0.price', '22.00')
        ->has('stylists', 2)->where('stylists.0.firstName', 'Ann')->where('groups', ['men', 'women'])
        ->where('rules.questions', ['Any allergies we should know about?'])->where('rules.depositNote', 'No deposit is needed.')
        ->missing('manageLink')->missing('rules.terms_key'));

    expect((string) $this->get($this->s->url())->getContent())->not->toContain('c2FtcGxl');
});

test('free times for a basket come from the published ranges', function () {
    $days = $this->getJson($this->s->url('times').'?options[]='.S::CUT.'&addOns[]='.S::BEARD.'&stylist='.S::BEN)->assertOk()->json('days');

    expect($days)->toHaveCount(1)->and($days[0]['date'])->toBe('2026-11-09')->and($days[0]['label'])->toBe('Mon 9 Nov')
        ->and($days[0]['times'])->toContain('11:30')->toContain('10:00')   // 10:00 is exactly now + the 2 h notice
        ->and($days[0]['times'])->not->toContain('10:15');

    $this->getJson($this->s->url('times').'?options[]=nothing&stylist=any')->assertStatus(422);
});

test('request → pulled by that shop only (Customer first) → till accepts → the client sees it booked', function () {
    $redirect = ($this->book)(['marketingSms' => true])->assertCreated()->json('redirect');
    $row = ($this->request)();

    expect($redirect)->toBe(url('/book/kings-cuts').'/r/'.$row->id)
        ->and($row->status)->toBe(SalonOnlineBookingStatus::Pending)
        ->and($row->kind?->value)->toBe('new')
        ->and($row->branch_id)->toBe($this->s->sync->leeds->id)
        ->and($row->requested_starts_at->toIso8601ZuluString())->toBe('2026-11-09T14:00:00Z')
        ->and($row->requested_user_id)->toBe(S::BEN)
        ->and(json_decode($row->services_json, true))->toBe([['productId' => S::CUT], ['productId' => S::BEARD]])
        ->and(json_decode($row->answers_json, true))->toBe([['question' => 'Any allergies we should know about?', 'answer' => 'No']])
        ->and($row->expires_at?->toIso8601ZuluString())->toBe('2026-11-09T08:30:00Z')
        ->and($row->terms_accepted_at?->toIso8601ZuluString())->toBe('2026-11-09T08:00:00Z')
        ->and([$row->marketing_sms, $row->marketing_email, $row->marketing_whats_app])->toBe([true, false, false])
        ->and($row->reference)->toMatch('/^WEB-[2-9A-Z]{6}$/')
        ->and([$row->appointment_id, $row->decided_at, $row->decision_reason])->toBe([null, null, '']);

    // The customer goes first in the pull, then the request; Bradford gets neither.
    $pulled = ($this->pulled)();
    expect(array_column($pulled, 'entity'))->toBe(['Customer', 'SalonOnlineBooking'])
        ->and($pulled[1]['payload']['customerId'])->toBe($pulled[0]['entityId'])
        ->and($pulled[1]['payload']['branchId'])->toBe(TillFixtures::LEEDS)
        ->and($pulled[0]['version'])->toBeLessThan($pulled[1]['version'])
        // The customer is the business's (hub-owned, every shop); the request is Leeds' only.
        ->and(array_column(($this->pulled)(bradford: true), 'entity'))->toBe(['Customer']);

    // Waiting: never "booked" before the till answers.
    $this->get($redirect)->assertOk()->assertInertia(fn (Assert $page) => $page->component('salon-public/request')
        ->where('request.status', 'pending')->where('request.time', '14:00')->where('request.services', ['Gents cut', 'Beard trim'])->where('request.manageUrl', null));

    // The till accepts (with its appointment) in its push.
    $this->travelTo('2026-11-09 08:01:00');
    $this->s->push([($this->answer)('accepted'), ...S::appointment('01K9S0000000000000000AP001', '2026-11-09T14:00:00Z', $pulled[0]['entityId'], 21)])->assertOk();

    $this->get($redirect)->assertOk()->assertInertia(fn (Assert $page) => $page->where('request.status', 'accepted')
        ->where('request.manageUrl', fn (string $url) => str_starts_with($url, url('/book/kings-cuts').'/m/01K9S0000000000000000AP001.')));
});

test('declined: the client is told the till\'s reason; an accept after expiresAt still counts', function () {
    $redirect = ($this->book)()->assertCreated()->json('redirect');
    $this->travelTo('2026-11-09 08:02:00');
    $this->s->push([($this->answer)('declined', ['appointmentId' => null, 'decisionReason' => 'Sorry, Ben is fully booked that afternoon.'])])->assertOk();

    $this->get($redirect)->assertInertia(fn (Assert $page) => $page->where('request.status', 'declined')->where('request.reason', 'Sorry, Ben is fully booked that afternoon.'));

    // A second request, answered long after its hold ran out.
    $second = ($this->book)(['time' => '15:00'])->assertCreated()->json('redirect');
    $this->travelTo('2026-11-09 09:30:00');
    $this->get($second)->assertInertia(fn (Assert $page) => $page->where('request.status', 'pending')->where('request.overdue', true));
    $this->s->push([($this->answer)('accepted', ['id' => ($this->request)($second)->id, 'reference' => ($this->request)($second)->reference, 'requestedStartsAt' => '2026-11-09T15:00:00Z'], 30)])->assertOk()->assertJson(['accepted' => 1]);
    $this->get($second)->assertInertia(fn (Assert $page) => $page->where('request.status', 'accepted'));
});

test('offline (no heartbeat for offlineAfterMin): "request only", and requests are still taken', function () {
    $this->travelTo('2026-11-09 08:09:59');
    $this->get($this->s->url())->assertInertia(fn (Assert $page) => $page->where('status', 'open'));

    $this->travelTo('2026-11-09 08:10:01');
    $this->get($this->s->url())->assertInertia(fn (Assert $page) => $page->where('status', 'offline'));
    $redirect = ($this->book)()->assertCreated()->json('redirect');
    $this->get($redirect)->assertInertia(fn (Assert $page) => $page->where('request.offline', true)->where('request.status', 'pending'));

    // The till comes back and answers it.
    $this->s->heartbeat(['at' => '2026-11-09T08:20:00Z'])->assertOk();
    $this->s->push([($this->answer)('accepted')])->assertOk();
    $this->get($redirect)->assertInertia(fn (Assert $page) => $page->where('request.status', 'accepted')->where('request.offline', false));
});

test('accepting: false or isOpen: false closes the page and shows the shop\'s phone; no request is taken', function () {
    $this->s->sync->leeds->forceFill(['phone' => '0113 496 0000'])->save();
    $this->s->heartbeat(['at' => '2026-11-09T08:00:30Z', 'accepting' => false])->assertOk();

    $this->get($this->s->url())->assertInertia(fn (Assert $page) => $page->where('status', 'closed')->where('shop.phone', '0113 496 0000'));
    ($this->book)()->assertStatus(422)->assertJsonValidationErrors('time');

    $this->s->heartbeat(['at' => '2026-11-09T08:01:00Z', 'accepting' => true])->assertOk();
    $this->s->putPage(S::page(['generatedAt' => '2026-11-09T08:01:00Z', 'rules.isOpen' => false]))->assertOk();
    $this->get($this->s->url())->assertInertia(fn (Assert $page) => $page->where('status', 'closed'));
    expect(SalonOnlineBooking::withoutCompanyScope()->count())->toBe(0);
});

test('a time not offered, a service not on the page, no terms or a missing field is refused', function (array $set, string $field) {
    ($this->book)($set)->assertStatus(422)->assertJsonValidationErrors($field);
    expect(SalonOnlineBooking::withoutCompanyScope()->count())->toBe(0);
})->with([
    'inside the notice' => [['time' => '09:30'], 'time'],
    'not a start' => [['time' => '10:45'], 'time'],
    'Ben does not do Short' => [['options' => [S::SHORT], 'addOns' => []], 'time'],
    'unknown service' => [['options' => ['01K9S0000000000000000SV999']], 'options'],
    'terms not ticked' => [['terms' => false], 'terms'],
    'no phone' => [['phone' => ''], 'phone'],
    'bad e-mail' => [['email' => 'zoe'], 'email'],
]);

test('a double tap is one request; the time it holds is not offered again for that stylist', function () {
    $key = 'tap-tap-tap-1';
    $first = ($this->book)(['clientKey' => $key])->assertCreated()->json('redirect');
    expect(($this->book)(['clientKey' => $key])->assertCreated()->json('redirect'))->toBe($first)
        ->and(SalonOnlineBooking::withoutCompanyScope()->count())->toBe(1);

    $times = $this->getJson($this->s->url('times').'?options[]='.S::CUT.'&stylist='.S::BEN)->json('days.0.times');
    expect($times)->not->toContain('14:00')->not->toContain('14:15')->toContain('14:45');
    ($this->book)(['time' => '14:00'])->assertStatus(422);

    // The hold ends at expiresAt.
    $this->travelTo('2026-11-09 08:31:00');
    $this->s->heartbeat(['at' => '2026-11-09T08:31:00Z'])->assertOk();
    expect($this->getJson($this->s->url('times').'?options[]='.S::CUT.'&stylist='.S::BEN)->json('days.0.times'))->toContain('14:00');
});

test('the client withdraws a pending request; once the till answered it cannot', function () {
    $redirect = ($this->book)()->assertCreated()->json('redirect');
    $id = ($this->request)($redirect)->id;

    $this->postJson($redirect.'/withdraw')->assertOk();
    expect(($this->request)($redirect)->status)->toBe(SalonOnlineBookingStatus::Cancelled)
        ->and(($this->pulled)()[1]['payload']['status'])->toBe('cancelled');

    $second = ($this->book)(['time' => '15:00'])->assertCreated()->json('redirect');
    $this->s->push([($this->answer)('accepted', ['id' => ($this->request)($second)->id, 'reference' => ($this->request)($second)->reference, 'requestedStartsAt' => '2026-11-09T15:00:00Z'])])->assertOk()->assertJson(['accepted' => 1]);
    $this->postJson($second.'/withdraw')->assertStatus(422);
    expect(SalonOnlineBooking::withoutCompanyScope()->find($id)?->status)->toBe(SalonOnlineBookingStatus::Cancelled);
});

test('marketing ticks are never assumed, `src` is recorded, and a returning client is matched only on e-mail and phone', function () {
    $first = ($this->request)(($this->book)(['src' => 'google'])->assertCreated()->json('redirect'));

    expect([$first->marketing_sms, $first->marketing_email, $first->marketing_whats_app])->toBe([false, false, false])
        ->and(SalonOnlineRequestSource::withoutCompanyScope()->find($first->id)?->src)->toBe('google');

    $second = ($this->request)(($this->book)(['time' => '15:00', 'phone' => '+44 7700 900123', 'email' => 'ZOE@example.co.uk', 'src' => '<script>'])->assertCreated()->json('redirect'));
    $third = ($this->request)(($this->book)(['time' => '16:00', 'phone' => '07700 900999'])->assertCreated()->json('redirect'));

    expect($second->customer_id)->toBe($first->customer_id)
        ->and($third->customer_id)->not->toBe($first->customer_id)
        ->and(SalonOnlineRequestSource::withoutCompanyScope()->find($second->id)?->src)->toBe('')
        ->and(Customer::withoutCompanyScope()->count())->toBe(2);
});

test('one shop\'s address never reaches another shop\'s request', function () {
    $redirect = ($this->book)()->assertCreated()->json('redirect');
    $this->s->sync->push([SalonRows::setting('vertical.salon', 'true', 1, branch: TillFixtures::BRADFORD)], bradford: true)->assertOk();
    $other = $this->s->putPage(bradford: true)->assertOk()->json('bookingUrl');
    $this->s->heartbeat(bradford: true)->assertOk();

    $this->get(str_replace(url('/book/kings-cuts'), $other, $redirect))->assertNotFound();
    $this->postJson(str_replace(url('/book/kings-cuts'), $other, $redirect).'/withdraw')->assertNotFound();
});
