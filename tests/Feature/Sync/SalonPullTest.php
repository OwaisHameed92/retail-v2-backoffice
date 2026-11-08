<?php

use App\Domain\Salon\Support\SalonShops;
use App\Domain\Tenancy\Enums\BusinessType;
use App\Domain\TillData\Actions\SetRolePermission;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SalonRows as Rows;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;

/*
 * Salon pack 2026-11-08 (W2; README Part C "refusals"): the hub-owned salon rows are pulled like Product, but only by
 * Salon / Barber shops; a shop of any other type never gets a salon row, a `salon.*` setting or right (its till would
 * refuse them), and a business with no salon pulls exactly what it pulled before.
 */

beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-11-08 10:00:00');
    $this->pulled = function (bool $bradford, int $since = 0): array {
        $reply = $this->sync->pull($since, bradford: $bradford)->assertOk();
        expect(ContractSchema::pullErrors($reply->json()))->toBe([]);

        return Pull::changes($reply);
    };
    $this->entities = fn (array $changes) => array_column($changes, 'entity');
    // Leeds is a salon (its till's wizard sent vertical.salon); Bradford is a grocery.
    $this->sync->push([Rows::setting('vertical.salon', 'true', 1), Rows::setting('setup.business_type', 'Salon', 2)])->assertOk();
});

it('knows a salon by its till\'s shop type or the business type', function () {
    expect(SalonShops::isSalonBranch($this->company->id, $this->sync->leeds->id))->toBeTrue()
        ->and(SalonShops::isSalonBranch($this->company->id, $this->sync->bradford->id))->toBeFalse()
        ->and(SalonShops::anySalon($this->company->id))->toBeTrue();

    $this->company->forceFill(['business_type' => BusinessType::Salon])->save();

    expect(SalonShops::isSalonBranch($this->company->id, $this->sync->bradford->id))->toBeTrue();
});

it('pulls a salon\'s hub-owned rows to the other salon shops only, never to a grocery', function () {
    $this->sync->push([
        Rows::envelope('SalonCommissionPlan', Rows::id('SalonCommissionPlan'), 3, ['name' => 'Stylists', 'tierMode' => 'marginal']),
        Rows::envelope('SalonLevel', Rows::id('SalonLevel'), 4, ['name' => 'Senior stylist', 'commissionPlanId' => Rows::id('SalonCommissionPlan')]),
        Rows::envelope('SalonResource', Rows::id('SalonResource'), 5, ['name' => 'Chair 1']),
        Rows::envelope('SalonStaffProfile', Rows::id('SalonStaffProfile'), 6, ['displayName' => 'Amy']),
    ])->assertOk();
    Pull::portalCreate($this->company, 'SalonServiceDetail', Rows::payload('SalonServiceDetail', Rows::id('SalonServiceDetail'), ['productId' => '01K5T0Q8C4000000000000P001', 'durationMin' => 45]));

    // Leeds pushed the first three itself: it gets only the portal's service detail. Bradford (a grocery): nothing salon.
    expect(($this->entities)(($this->pulled)(false)))->toBe(['SalonServiceDetail'])
        ->and(array_filter(($this->entities)(($this->pulled)(true)), fn (string $e) => str_starts_with($e, 'Salon')))->toBe([]);

    // Bradford becomes a salon: the company-wide salon rows reach it in version order (the plan before the level that
    // names it; the portal's detail was stamped first, at its save); Leeds's own
    // chair (SalonResource, branchId Leeds) and Leeds's staff (branch-owned) never do.
    $this->sync->push([Rows::setting('vertical.salon', 'true', 1, branch: TillFixtures::BRADFORD)], bradford: true)->assertOk();

    $changes = ($this->pulled)(true);
    expect(array_values(array_filter(($this->entities)($changes), fn (string $e) => str_starts_with($e, 'Salon'))))->toBe(['SalonServiceDetail', 'SalonCommissionPlan', 'SalonLevel'])
        ->and(collect($changes)->firstWhere('entity', 'SalonLevel')['payload'])->toMatchArray(['name' => 'Senior stylist', 'rank' => 1, 'commissionPlanId' => Rows::id('SalonCommissionPlan')]);
});

it('never sends a salon setting, right or role right to a shop that is not a salon', function () {
    $role = '01K5T0Q8C4000000000000G002';
    Pull::portalCreate($this->company, 'Role', Pull::payload('Role', $role, ['name' => 'Manager', 'isSystem' => false]), ['permissions' => [
        ['roleId' => $role, 'permissionKey' => 'sale.refund', 'role' => null], ['roleId' => $role, 'permissionKey' => 'salon.tips_manage', 'role' => null],
    ]]);
    app(SetRolePermission::class)->handle($this->company, $role, 'salon.diary_manage', true);
    app(SetRolePermission::class)->handle($this->company, $role, 'sale.no_sale', true);
    // A company-scope salon key and the flag at company scope from a till (the till writes them at branch scope).
    $this->sync->push([Rows::setting('salon.tips.ask', 'true', 3, 'company'), Rows::setting('salon.staff_word', 'Barber', 4)])->assertOk();

    $grocery = ($this->pulled)(true);
    $keys = fn (array $changes, string $entity, string $member) => array_map(fn ($c) => $c['payload'][$member], array_values(array_filter($changes, fn ($c) => $c['entity'] === $entity)));

    expect($keys($grocery, 'Setting', 'key'))->toBe([])
        ->and($keys($grocery, 'RolePermission', 'permissionKey'))->toBe(['sale.no_sale'])
        ->and(collect($grocery)->firstWhere('entity', 'Role')['payload']['permissions'])->toEqual([['roleId' => $role, 'permissionKey' => 'sale.refund', 'role' => null]]);   // MySQL's json column may reorder members

    // The salon (Leeds) gets the portal's salon right and the role as stored; never its own settings back.
    $salon = ($this->pulled)(false);
    expect($keys($salon, 'RolePermission', 'permissionKey'))->toBe(['salon.diary_manage', 'sale.no_sale'])
        ->and(count(collect($salon)->firstWhere('entity', 'Role')['payload']['permissions']))->toBe(2)
        ->and($keys($salon, 'Setting', 'key'))->toBe([]);
});

it('gives a grocery a byte-identical pull whatever the salon next door sends', function () {
    $rows = array_values(array_filter(TillFixtures::sample('pull-reply.json')['changes'], fn ($c) => $c['entity'] !== 'WebOrder'));
    $push = array_map(fn (array $row, int $n) => TillFixtures::envelope($row['entity'], $row['payload'], $n + 10, ['version' => 1]), array_reverse($rows), array_keys($rows));
    $this->sync->push($push)->assertOk();

    $before = $this->sync->pull(0, bradford: true)->assertOk()->getContent();
    expect(count(json_decode((string) $before, true)['changes']))->toBe(count($rows));

    // The salon pushes hub-owned salon rows, salon settings (one at company scope), salon rights and a portal salon row.
    $this->sync->push([
        ...array_map(fn (string $e, int $i) => Rows::envelope($e, Rows::id($e, 2), 100 + $i), $hub = ['SalonCommissionPlan', 'SalonLevel', 'SalonStampCardRule', 'SalonPackageDefinition', 'SalonConsultationFormTemplate'], array_keys($hub)),
        Rows::setting('salon.tips.policy', 'equal', 200, 'company'),
        Rows::setting('salon.online.enabled', 'true', 201),
        Rows::right('01K5T0Q8C4000000000000G001', 'salon.settings', 202),
    ])->assertOk();
    Pull::portalCreate($this->company, 'SalonOnlineBooking', Rows::onlineRequest('new'), ['branch_id' => $this->sync->leeds->id]);

    expect($this->sync->pull(0, bradford: true)->assertOk()->getContent())->toBe($before);
});
