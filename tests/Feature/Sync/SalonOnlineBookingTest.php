<?php

use App\Domain\Salon\Exceptions\SalonBookingRefused;
use App\Domain\TillData\Enums\SalonOnlineBookingStatus;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SalonRows as Rows;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * Salon pack 2026-11-08 (W2; salon/README.md §5, §7): a SalonOnlineBooking request is hub-owned. The portal writes the
 * request for one shop and sends it in that shop's pull only; the till answers on the same row (accepted with its
 * appointment, or declined with a reason) and its answer always wins; once answered, the portal can no longer change
 * the row. No booking page yet (W3): the requests here are test-made through the model.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-11-09 07:55:00');
    // Both shops are salons: a request still goes only to the shop it names.
    $this->sync->push([Rows::setting('vertical.salon', 'true', 1)])->assertOk();
    $this->sync->push([Rows::setting('vertical.salon', 'true', 1, branch: TillFixtures::BRADFORD)], bradford: true)->assertOk();

    $this->new = Rows::onlineRequest('new');
    $this->id = $this->new['id'];
    $this->request = fn (array $payload) => Pull::portalCreate($this->company, 'SalonOnlineBooking', $payload, ['branch_id' => $this->sync->leeds->id]);
    $this->bookings = function (bool $bradford, int $since = 0): array {
        $reply = $this->sync->pull($since, bradford: $bradford)->assertOk();
        expect(ContractSchema::pullErrors($reply->json()))->toBe([]);

        return array_values(array_filter(Pull::changes($reply), fn (array $c) => $c['entity'] === 'SalonOnlineBooking'));
    };
    // The till's answer, pushed back as a U of the same row (the pack's accepted sample).
    $this->answer = fn (array $overrides = [], int $seq = 5, ?int $base = null) => TillFixtures::envelope('SalonOnlineBooking', [...Rows::onlineRequest('accepted'), ...$overrides], $seq,
        ['op' => 'U', ...($base === null ? [] : ['baseVersion' => $base])]);
    $this->row = fn () => SalonOnlineBooking::withoutCompanyScope()->findOrFail($this->id);
});

it('sends a portal-written request in the pull of the shop it names, and no other', function () {
    ($this->request)($this->new);

    $leeds = ($this->bookings)(false);
    expect($leeds)->toHaveCount(1)
        ->and($leeds[0])->toMatchArray(['op' => 'I', 'entityId' => $this->id, 'branchId' => TillFixtures::LEEDS])
        ->and($leeds[0]['payload'])->toMatchArray([
            'reference' => 'WEB-000123', 'kind' => 'new', 'status' => 'pending', 'appointmentId' => null, 'decidedAt' => null,
            'branchId' => TillFixtures::LEEDS, 'servicesJson' => $this->new['servicesJson'], 'marketingSms' => true, 'depositPaid' => 0,
        ])
        ->and(($this->bookings)(true))->toBe([]);
});

it('takes the till\'s answer, and never sends it back to that till', function () {
    ($this->request)($this->new);
    $version = ($this->bookings)(false)[0]['version'];

    $this->travelTo('2026-11-09 08:01:00');
    $this->sync->push([($this->answer)(base: $version)])->assertOk()->assertJson(['accepted' => 1]);

    $row = ($this->row)();
    expect($row->status)->toBe(SalonOnlineBookingStatus::Accepted)
        ->and([$row->appointment_id, $row->decided_at?->toIso8601ZuluString(), $row->origin_branch_id])->toBe(['01K9S0000000000000000AP001', '2026-11-09T08:01:00Z', $this->sync->leeds->id])
        ->and(($this->bookings)(false, $version))->toBe([])
        ->and(($this->bookings)(true))->toBe([])
        ->and(DB::table('sync_conflicts')->count())->toBe(0);

    // The same answer again (a retry with a new seq) changes nothing.
    $this->sync->push([($this->answer)(seq: 6, base: $version)])->assertOk();
    expect(DB::table('sync_conflicts')->count())->toBe(0)->and(($this->row)()->status)->toBe(SalonOnlineBookingStatus::Accepted);
});

it('lets the till\'s answer win over a portal change made after the till read the request', function () {
    ($this->request)($this->new);
    $version = ($this->bookings)(false)[0]['version'];

    // The client withdraws on the portal; meanwhile the till had already accepted (its answer names the old version).
    $this->travelTo('2026-11-09 08:02:00');
    Pull::portalUpdate($this->company, 'SalonOnlineBooking', $this->id, ['status' => 'cancelled']);
    $this->travelTo('2026-11-09 08:03:00');
    $this->sync->push([($this->answer)(base: $version)])->assertOk();

    expect(($this->row)()->status)->toBe(SalonOnlineBookingStatus::Accepted)
        ->and(($this->row)()->appointment_id)->toBe('01K9S0000000000000000AP001')
        ->and(DB::table('sync_conflicts')->count())->toBe(0);

    // A declined answer from an older till (no baseVersion) wins the same way.
    $declined = [...$this->new, 'id' => '01K9S0000000000000000RQ003', 'reference' => 'WEB-000125'];
    ($this->request)($declined);
    $this->travelTo('2026-11-09 08:10:00');
    Pull::portalUpdate($this->company, 'SalonOnlineBooking', $declined['id'], ['notes' => 'Changed on the portal later']);
    $answer = TillFixtures::envelope('SalonOnlineBooking', [...$declined, 'status' => 'declined', 'decidedAt' => '2026-11-09T08:05:00Z', 'decisionReason' => 'Sorry, Amy is away that day.', 'rowVersion' => 2, 'updatedAt' => '2026-11-09T08:05:00Z'], 7, ['op' => 'U']);
    $this->sync->push([$answer])->assertOk();

    expect(DB::table('salon_online_bookings')->where('id', $declined['id'])->first())->toMatchArray(['status' => 'declined', 'decision_reason' => 'Sorry, Amy is away that day.', 'notes' => 'Short back and sides please']);
});

it('keeps the portal\'s newer version when the till pushes something that is not an answer', function () {
    ($this->request)($this->new);
    $this->travelTo('2026-11-09 08:02:00');
    Pull::portalUpdate($this->company, 'SalonOnlineBooking', $this->id, ['status' => 'cancelled']);

    // An older till pushes the request back still pending (no answer), from before the portal's change.
    $this->sync->push([TillFixtures::envelope('SalonOnlineBooking', [...$this->new, 'rowVersion' => 1, 'updatedAt' => '2026-11-09T07:58:00Z'], 5, ['op' => 'U', 'at' => '2026-11-09T07:58:00Z'])])->assertOk();

    expect(($this->row)()->status)->toBe(SalonOnlineBookingStatus::Cancelled)
        ->and(DB::table('sync_conflicts')->where('entity', 'SalonOnlineBooking')->value('kind'))->toBe('hubEditNewer');
});

it('refuses a portal change to a request the till has answered, and the till\'s own members', function () {
    ($this->request)($this->new);
    $this->sync->push([($this->answer)()])->assertOk();
    $before = (array) DB::table('salon_online_bookings')->where('id', $this->id)->first();

    foreach ([['status' => 'cancelled'], ['notes' => 'Can I come later?'], ['status' => 'expired']] as $change) {
        expect(fn () => Pull::portalUpdate($this->company, 'SalonOnlineBooking', $this->id, $change))->toThrow(SalonBookingRefused::class);
    }

    expect(fn () => Pull::portalDelete($this->company, 'SalonOnlineBooking', $this->id))->toThrow(SalonBookingRefused::class)
        ->and((array) DB::table('salon_online_bookings')->where('id', $this->id)->first())->toBe($before);

    // A new request may not carry an answer status, the till's members, an appointment (kind new) or no shop.
    $fresh = fn (array $overrides) => [...$this->new, 'id' => '01K9S0000000000000000RQ009', ...$overrides];
    foreach ([['status' => 'accepted'], ['status' => 'declined'], ['decidedAt' => '2026-11-09T08:00:00Z'], ['decisionReason' => 'No'], ['appointmentId' => '01K9S0000000000000000AP001']] as $bad) {
        expect(fn () => ($this->request)($fresh($bad)))->toThrow(SalonBookingRefused::class);
    }

    expect(fn () => Pull::portalCreate($this->company, 'SalonOnlineBooking', $fresh([]), ['branch_id' => null]))->toThrow(SalonBookingRefused::class);
});

it('lets the portal correct or withdraw its pending request, and name the appointment of a preferences request', function () {
    ($this->request)($this->new);
    Pull::portalUpdate($this->company, 'SalonOnlineBooking', $this->id, ['notes' => 'Fringe trim too']);
    Pull::portalUpdate($this->company, 'SalonOnlineBooking', $this->id, ['status' => 'cancelled']);
    Pull::portalCreate($this->company, 'SalonOnlineBooking', Rows::onlineRequest('preferences'), ['branch_id' => $this->sync->leeds->id]);

    expect(($this->row)()->status)->toBe(SalonOnlineBookingStatus::Cancelled)
        ->and(array_column(array_column(($this->bookings)(false), 'payload'), 'kind'))->toBe(['new', 'preferences'])
        ->and(fn () => Pull::portalUpdate($this->company, 'SalonOnlineBooking', $this->id, ['status' => 'pending']))->toThrow(SalonBookingRefused::class)
        ->and(fn () => Pull::portalUpdate($this->company, 'SalonOnlineBooking', $this->id, ['branch_id' => $this->sync->bradford->id]))->toThrow(SalonBookingRefused::class);
});

it('refuses an answer from another shop\'s till', function () {
    ($this->request)($this->new);

    $this->sync->push([[...($this->answer)(), 'branchId' => TillFixtures::BRADFORD]], bradford: true)->assertStatus(422);

    expect(($this->row)()->status)->toBe(SalonOnlineBookingStatus::Pending);
});
