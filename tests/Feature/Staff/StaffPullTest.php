<?php

use App\Domain\Setup\Actions\DeleteSetupRow;
use App\Domain\Setup\Actions\SavePaymentType;
use App\Domain\Setup\Actions\SaveReason;
use App\Domain\Setup\Actions\SaveSupplier;
use App\Domain\Staff\Actions\AssignStaffFob;
use App\Domain\Staff\Actions\DeleteStaffMember;
use App\Domain\Staff\Actions\SaveRolePermissions;
use App\Domain\Staff\Actions\SaveStaffMember;
use App\Domain\Staff\Actions\SetStaffPin;
use App\Domain\Staff\Support\TillPinHasher;
use App\Domain\TillData\Models\Supplier;
use App\Domain\TillData\Sync\SyncRowIds;
use Tests\Feature\Staff\StaffFixtures as Staff;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 4.5: portal edits of staff, roles, suppliers, payment types and reasons reach every till's pull. */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-10-22 09:00:00');
    Staff::roles($this->company);
    $this->valid = fn ($reply) => expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
    $this->rows = fn (string $entity, bool $bradford = false) => array_values(array_filter(
        Pull::changes($this->sync->pull(0, bradford: $bradford)),
        fn (array $change) => $change['entity'] === $entity,
    ));
});

test('a new staff member reaches every till with the PIN hash only; no blank fob is sent', function () {
    $member = Staff::member($this->company, 'Aisha Patel', '4821');

    foreach ([false, true] as $bradford) {
        $reply = $this->sync->pull(0, bradford: $bradford)->assertOk();
        ($this->valid)($reply);
        $user = collect(Pull::changes($reply))->firstWhere('entityId', $member->id);

        expect($user)->toMatchArray(['entity' => 'User', 'op' => 'I', 'branchId' => '', 'companyId' => TillFixtures::COMPANY])
            ->and($user['payload'])->toMatchArray(['name' => 'Aisha Patel', 'roleId' => Staff::CASHIER, 'isActive' => true, 'ratePerHour' => 11.44])
            ->and($user['payload'])->not->toHaveKey('rfid')
            ->and((new TillPinHasher)->verify('4821', $user['payload']['pinHash']))->toBeTrue()
            ->and(json_encode($reply->json()))->not->toContain('"4821"');
    }
});

test('edits, a new PIN and a fob are pulled as U; a removal as D', function () {
    $member = Staff::member($this->company, 'Aisha Patel', '4821');
    $first = collect(($this->rows)('User'))->firstWhere('entityId', $member->id)['payload']['pinHash'];

    $this->travel(1)->minutes();
    app(SaveStaffMember::class)->handle($this->company, $member->id, ['name' => 'Aisha Shah', 'role_id' => Staff::MANAGER, 'is_active' => false]);
    $edited = collect(($this->rows)('User'))->firstWhere('entityId', $member->id);
    expect($edited['op'])->toBe('U')
        ->and($edited['payload'])->toMatchArray(['name' => 'Aisha Shah', 'roleId' => Staff::MANAGER, 'isActive' => false, 'pinHash' => $first]);

    app(SetStaffPin::class)->handle($this->company, $member->id, '5930');
    app(AssignStaffFob::class)->handle($this->company, $member->id, '04A1B2C3');
    $reply = $this->sync->pull(0, bradford: true);
    ($this->valid)($reply);
    $payload = collect(Pull::changes($reply))->firstWhere('entityId', $member->id)['payload'];
    expect($payload['rfid'])->toBe('04A1B2C3')
        ->and($payload['pinHash'])->not->toBe($first)
        ->and((new TillPinHasher)->verify('5930', $payload['pinHash']))->toBeTrue()
        ->and(json_encode($reply->json()))->not->toContain('5930');

    app(DeleteStaffMember::class)->handle($this->company, $member->id);
    expect(collect(($this->rows)('User'))->firstWhere('entityId', $member->id)['op'])->toBe('D');
});

test('role permission edits are pulled as keyed I and D rows', function () {
    app(SaveRolePermissions::class)->handle($this->company, Staff::MANAGER, ['sale.refund', 'business.apply_all_shops']);
    app(SaveRolePermissions::class)->handle($this->company, Staff::MANAGER, ['business.apply_all_shops']);
    $reply = $this->sync->pull(0);
    ($this->valid)($reply);

    $rows = collect(Pull::changes($reply))->where('entity', 'RolePermission')
        ->map(fn ($c) => [$c['entityId'], $c['op'], $c['payload']])->values()->all();

    expect($rows)->toEqualCanonicalizing([
        [SyncRowIds::rolePermission(Staff::MANAGER, 'business.apply_all_shops'), 'I', ['roleId' => Staff::MANAGER, 'permissionKey' => 'business.apply_all_shops']],
        [SyncRowIds::rolePermission(Staff::MANAGER, 'sale.refund'), 'D', ['roleId' => Staff::MANAGER, 'permissionKey' => 'sale.refund']],
    ]);
});

test('suppliers, payment types and reasons reach every till, with their edits and removals', function () {
    $supplier = app(SaveSupplier::class)->handle($this->company, null, [
        'name' => 'Aire Valley Cash & Carry', 'terms_kind' => 'netDays', 'payment_terms_days' => 30, 'order_method' => 'portal',
        'minimum_order_value' => '50', 'delivery_days' => ['friday', 'tuesday'], 'postcode' => 'ls4 2qd',
    ]);
    $cash = app(SavePaymentType::class)->handle($this->company, null, ['name' => 'Cash', 'is_cash' => true, 'opens_drawer' => true]);
    $card = app(SavePaymentType::class)->handle($this->company, null, ['name' => 'Card', 'is_card' => true]);
    $reason = app(SaveReason::class)->handle($this->company, null, ['type' => 'refund', 'text' => 'Damaged item']);

    $reply = $this->sync->pull(0, bradford: true);
    ($this->valid)($reply);
    $changes = collect(Pull::changes($reply));

    expect($changes->firstWhere('entityId', $supplier->id)['payload'])->toMatchArray([
        'name' => 'Aire Valley Cash & Carry', 'code' => 'AIREVA', 'termsKind' => 'netDays', 'paymentTermsDays' => 30,
        'minimumOrderValue' => 50, 'orderMethod' => 'portal', 'deliveryDays' => 'tuesday, friday', 'postcode' => 'LS4 2QD', 'isActive' => true,
    ])
        ->and($changes->firstWhere('entityId', $cash->id)['payload'])->toMatchArray(['name' => 'Cash', 'position' => 1, 'isCash' => true, 'opensDrawer' => true, 'showOnPayment' => true, 'isActive' => true])
        ->and($changes->firstWhere('entityId', $card->id)['payload'])->toMatchArray(['position' => 2, 'isCard' => true, 'isCash' => false])
        ->and($changes->firstWhere('entityId', $reason->id)['payload'])->toMatchArray(['type' => 'refund', 'text' => 'Damaged item', 'position' => 1, 'accountCode' => null]);

    $this->travel(1)->minutes();
    app(SaveReason::class)->handle($this->company, $reason->id, ['type' => 'refund', 'text' => 'Damaged or faulty', 'is_active' => false]);
    app(DeleteSetupRow::class)->handle($this->company, Supplier::class, $supplier->id);
    $changes = collect(Pull::changes($this->sync->pull(0)));

    expect($changes->firstWhere('entityId', $reason->id))->toMatchArray(['op' => 'U'])
        ->and($changes->firstWhere('entityId', $reason->id)['payload'])->toMatchArray(['text' => 'Damaged or faulty', 'isActive' => false])
        ->and($changes->firstWhere('entityId', $supplier->id)['op'])->toBe('D');
});
