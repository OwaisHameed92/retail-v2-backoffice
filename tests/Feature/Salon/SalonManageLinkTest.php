<?php

use App\Domain\Salon\Online\ManageLinkToken;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Salon\SalonOnline as S;
use Tests\Feature\Sync\SalonRows;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * W3a, salon pack README §8, §11 (checklist 7): the manage link `{manageUrl}{appointmentId}.{token}`, no password. The
 * token is checked in constant time against the shop's newest key; moves and cancels are new requests until
 * `changeWindowHours` before the start ("Please ring the shop" inside it); marketing choices any time.
 */

const SALON_KEY = 'c2FtcGxlLWtleS1mb3ItdGhlLXBvcnRhbC1wYWNr';
const SALON_APPOINTMENT = '01K9S0000000000000000AP001';
const SALON_CUSTOMER = '01K9S0000000000000000CM001';   // a valid ULID (the pack's CU001 has a U)

beforeEach(function () {
    $this->travelTo('2026-11-09 08:00:00');
    $this->s = new S($this);
    $this->s->open();
    // Zoe is a customer of the business (pushed by the till), booked for tomorrow 14:00 with Ben.
    $this->s->push([
        SalonRows::envelope('Customer', SALON_CUSTOMER, 10, ['name' => 'Zoe Patel', 'phone' => '07700900123', 'email' => 'zoe@example.co.uk', 'anonymisedAt' => null, 'dob' => null]),
        ...S::appointment(SALON_APPOINTMENT, '2026-11-10T14:00:00Z', SALON_CUSTOMER, 11),
    ])->assertOk();
    // Tomorrow's free times for the move.
    $this->s->putPage(S::page(['generatedAt' => '2026-11-09T08:00:30Z', 'availability.days.1' => [
        'date' => '2026-11-10', 'utcOffsetMinutes' => 0,
        'items' => [['productId' => S::CUT, 'stylists' => [['userId' => S::BEN, 'ranges' => ['10:00-12:00']]]]],
    ]]))->assertOk();
    $this->link = fn (string $appointment = SALON_APPOINTMENT, string $key = SALON_KEY) => $this->s->url('m/'.$appointment.'.'.ManageLinkToken::make($key, $appointment));
    $this->requests = fn () => SalonOnlineBooking::withoutCompanyScope()->orderBy('created_at')->get();
});

test('the token is the first 22 characters of base64url(HMAC-SHA256(key, appointmentId)), no padding', function () {
    $expected = substr(rtrim(strtr(base64_encode(hash_hmac('sha256', SALON_APPOINTMENT, SALON_KEY, true)), '+/', '-_'), '='), 0, 22);

    expect(ManageLinkToken::make(SALON_KEY, SALON_APPOINTMENT))->toBe($expected)->toHaveLength(22)->not->toContain('=')
        ->and(ManageLinkToken::link($this->s->shop(), SALON_APPOINTMENT))->toBe(url('/book/kings-cuts').'/m/'.SALON_APPOINTMENT.'.'.$expected);
});

test('a valid link opens the appointment as the till pushed it, without the client\'s contact details', function () {
    $response = $this->get(($this->link)())->assertOk()->assertInertia(fn (Assert $page) => $page->component('salon-public/manage')
        ->where('appointment.id', SALON_APPOINTMENT)->where('appointment.time', '14:00')->where('appointment.when', 'Tuesday 10 November 2026')
        ->where('appointment.stylist', 'Ben')->where('appointment.services', ['Gents cut'])->where('appointment.firstName', 'Zoe')
        ->where('changeable', true)->where('canMoveOnline', true)->where('consent', ['sms' => false, 'email' => false, 'whatsApp' => false]));

    expect((string) $response->getContent())->not->toContain('07700900123')->not->toContain('zoe@example')->not->toContain('Private note');
});

test('a wrong token, a link made with an earlier key or another shop\'s address opens nothing', function () {
    $token = ManageLinkToken::make(SALON_KEY, SALON_APPOINTMENT);
    $wrong = substr($token, 0, 21).($token[21] === 'A' ? 'B' : 'A');

    $this->get($this->s->url('m/'.SALON_APPOINTMENT.'.'.$wrong))->assertNotFound()->assertInertia(fn (Assert $page) => $page->where('appointment', null));
    $this->get(($this->link)(SALON_APPOINTMENT, 'another-key'))->assertNotFound();
    $this->get(($this->link)('01K9S0000000000000000AP999'))->assertNotFound();
    $this->get($this->s->url('m/'.SALON_APPOINTMENT.'.short'))->assertNotFound();

    // The shop's key changes (a new keyId): links made with the old one stop working.
    $this->s->putPage(S::page(['generatedAt' => '2026-11-09T08:01:00Z', 'manageLink' => ['keyId' => 'newera000001', 'key' => 'bmV3LWtleQ']]))->assertOk();
    $this->get(($this->link)())->assertNotFound();
    $this->get(($this->link)(SALON_APPOINTMENT, 'bmV3LWtleQ'))->assertOk();

    // Bradford (another salon) with the same token: never Leeds' appointment.
    $this->s->sync->push([SalonRows::setting('vertical.salon', 'true', 1, branch: TillFixtures::BRADFORD)], bradford: true)->assertOk();
    $other = $this->s->putPage(S::page(['manageLink' => ['keyId' => 'newera000001', 'key' => 'bmV3LWtleQ']]), bradford: true)->assertOk()->json('manageUrl');
    $this->get($other.SALON_APPOINTMENT.'.'.ManageLinkToken::make('bmV3LWtleQ', SALON_APPOINTMENT))->assertNotFound();
});

test('outside the change window: move and cancel send new requests naming the appointment', function () {
    $times = $this->getJson(($this->link)().'/times?stylist='.S::BEN)->assertOk()->json('days');
    expect(array_column($times, 'date'))->toContain('2026-11-10');

    $this->s->postJson(($this->link)().'/move', ['stylist' => S::BEN, 'date' => '2026-11-10', 'time' => '11:00'])->assertCreated();
    $this->s->postJson(($this->link)().'/cancel', [])->assertCreated();

    [$move, $cancel] = ($this->requests)()->all();
    expect($move->kind?->value)->toBe('reschedule')->and($move->appointment_id)->toBe(SALON_APPOINTMENT)
        ->and($move->requested_starts_at->toIso8601ZuluString())->toBe('2026-11-10T11:00:00Z')->and($move->requested_user_id)->toBe(S::BEN)
        ->and($move->customer_id)->toBe(SALON_CUSTOMER)->and($move->status?->value)->toBe('pending')
        ->and(json_decode($move->services_json, true))->toBe([['productId' => S::CUT]])
        ->and([$move->client_first_name, $move->client_last_name])->toBe(['Zoe', 'Patel'])
        ->and($cancel->kind?->value)->toBe('cancel')->and($cancel->appointment_id)->toBe(SALON_APPOINTMENT);

    // Both go to Leeds' till in its pull, valid against the entity schema.
    $reply = $this->s->sync->pull(0)->assertOk();
    expect(ContractSchema::pullErrors($reply->json()))->toBe([]);
    $kinds = array_map(fn ($c) => $c['payload']['kind'], array_values(array_filter($reply->json('changes'), fn ($c) => $c['entity'] === 'SalonOnlineBooking')));
    expect($kinds)->toBe(['reschedule', 'cancel']);

    // A time not offered is refused.
    $this->s->postJson(($this->link)().'/move', ['stylist' => S::BEN, 'date' => '2026-11-10', 'time' => '13:00'])->assertStatus(422)->assertJsonValidationErrors('time');
});

test('inside the change window: "Please ring the shop", and no move or cancel is sent', function () {
    $this->travelTo('2026-11-09 14:00:01');   // 24 hours before is 14:00 today
    $this->s->heartbeat(['at' => now('UTC')->toIso8601ZuluString()])->assertOk();

    $this->get(($this->link)())->assertOk()->assertInertia(fn (Assert $page) => $page->where('changeable', false)->where('changeWindowHours', 24));
    $this->s->postJson(($this->link)().'/cancel', [])->assertStatus(422)->assertJsonPath('errors.time.0', 'This appointment can no longer be changed online. Please ring the shop.');
    $this->s->postJson(($this->link)().'/move', ['stylist' => S::BEN, 'date' => '2026-11-10', 'time' => '11:00'])->assertStatus(422);
    expect(($this->requests)())->toHaveCount(0);
});

test('marketing choices any time: three ticks showing the current consent, sent as a preferences request', function () {
    // The till pushed an SMS consent for Zoe.
    $this->s->push([SalonRows::envelope('Consent', '01K9S0000000000000000CN001', 30, [
        'customerId' => SALON_CUSTOMER, 'channel' => 'sms', 'given' => true, 'source' => 'onlineBooking', 'at' => '2026-11-09T07:00:00Z', 'withdrawnAt' => null, 'isActive' => true,
    ])])->assertOk();
    $this->travelTo('2026-11-10 13:00:00');   // inside the change window: preferences still work
    $this->s->heartbeat(['at' => now('UTC')->toIso8601ZuluString()])->assertOk();

    $this->get(($this->link)())->assertInertia(fn (Assert $page) => $page->where('consent', ['sms' => true, 'email' => false, 'whatsApp' => false]));
    $this->s->postJson(($this->link)().'/preferences', ['sms' => false, 'email' => true, 'whatsApp' => false])->assertCreated();

    $request = ($this->requests)()->sole();
    expect($request->kind?->value)->toBe('preferences')->and($request->customer_id)->toBe(SALON_CUSTOMER)
        ->and($request->appointment_id)->toBe(SALON_APPOINTMENT)->and($request->expires_at)->toBeNull()
        ->and([$request->marketing_sms, $request->marketing_email, $request->marketing_whats_app])->toBe([false, true, false]);
});
