<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\CashMovementType;
use App\Domain\TillData\Models\CashMovement;
use App\Domain\TillData\Models\ClockEvent;
use Tests\Feature\TillData\TillFixtures;

/*
 * Till 0.1.15 pushed data (PORTAL-CHANGES-0.1.15 items 1, 6, 13): ClockEvent.registerId, CashMovement.type
 * accountPayment, and a no-shift CashMovement (`shiftId: ""`) filled in later by a `U`.
 */

beforeEach(function () {
    [$this->company, $this->leeds] = TillFixtures::tenant();
    $this->asCompany = fn (Closure $callback) => app(CurrentCompany::class)->runAs($this->company, $callback);
});

/** @return array<string, mixed> */
function till0115Row(string $id, int $version, array $fields): array
{
    return [
        'id' => $id, 'companyId' => TillFixtures::COMPANY, 'branchId' => TillFixtures::LEEDS, 'registerId' => TillFixtures::TILL_1,
        'createdAt' => '2026-09-30T08:00:00Z', 'updatedAt' => '2026-09-30T08:0'.$version.':00Z', 'rowVersion' => $version,
        'deletedAt' => null, 'isDeleted' => false, 'domainEvents' => null, ...$fields,
    ];
}

it('stores cash taken with no shift open, then the shift id its update brings; accepts accountPayment', function () {
    $id = '01K5VB0000000CMR0010000001';
    $cash = fn (int $version, string $shift) => till0115Row($id, $version, [
        'shiftId' => $shift, 'userId' => '01K5T0Q8C4000000000000A001', 'type' => 'accountPayment', 'amount' => 12.5,
        'reasonId' => '', 'note' => '', 'at' => '2026-09-30T08:00:00Z',
    ]);

    $first = TillFixtures::apply($this->company, $this->leeds, [TillFixtures::envelope('CashMovement', $cash(1, ''), 1)]);
    expect($first->rejected)->toBe([]);

    ($this->asCompany)(function () use ($id) {
        $row = CashMovement::query()->findOrFail($id);
        expect($row->shift_id)->toBe('')->and($row->type)->toBe(CashMovementType::AccountPayment);
    });

    $second = TillFixtures::apply($this->company, $this->leeds, [TillFixtures::envelope('CashMovement', $cash(2, '01K5VB0000000SHR0010000001'), 2, ['op' => 'U'])]);
    expect($second->rejected)->toBe([]);

    ($this->asCompany)(fn () => expect(CashMovement::query()->findOrFail($id)->shift_id)->toBe('01K5VB0000000SHR0010000001'));
});

it('stores ClockEvent.registerId, null on older rows', function () {
    $event = fn (string $id, ?string $register) => till0115Row($id, 1, [
        'userId' => '01K5T0Q8C4000000000000A001', 'type' => 'in', 'at' => '2026-09-30T08:00:00Z', 'note' => '', 'registerId' => $register,
    ]);

    $result = TillFixtures::apply($this->company, $this->leeds, [
        TillFixtures::envelope('ClockEvent', $event('01K5VB0000000CER0010000001', TillFixtures::TILL_1), 1),
        TillFixtures::envelope('ClockEvent', $event('01K5VB0000000CER0010000002', null), 2, ['registerId' => '']),
    ]);
    expect($result->rejected)->toBe([]);

    ($this->asCompany)(function () {
        expect(ClockEvent::query()->findOrFail('01K5VB0000000CER0010000001')->register_id)->toBe(TillFixtures::TILL_1)
            ->and(ClockEvent::query()->findOrFail('01K5VB0000000CER0010000002')->register_id)->toBeNull();
    });
});
