<?php

use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\SaveTillSetting;
use App\Domain\TillData\Actions\SetRolePermission;
use App\Domain\TillData\Enums\SettingScope;
use App\Domain\TillData\Sync\SyncRowIds;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.9B: settings and role permissions in the pull (contract v1.4.1 §10.3, §10.7, §19.4 test 13). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->valid = fn ($reply) => expect(SyncApiFixtures::schemaErrors($reply, 'pull-reply.schema.json'))->toBe([]);
    $this->save = fn (SettingScope $scope, string $key, ?string $value, $branch = null) => app(SaveTillSetting::class)->handle($this->company, $scope, $branch, $key, $value);
    $this->role = Pull::portalCreate($this->company, 'Role', Pull::payload('Role', '01K5T0Q8C4000000000000G002', ['name' => 'Manager', 'isSystem' => false]));
    $this->owner = Pull::portalCreate($this->company, 'Role', Pull::payload('Role', '01K5T0Q8C4000000000000G001', ['name' => 'Owner', 'isSystem' => true]));
});

test('a company setting changed on the portal reaches every till in the keyed envelope, with the till\'s ids', function () {
    ($this->save)(SettingScope::Company, 'receipt.footer_text', 'Thank you for shopping at Kirkgate!');

    foreach ([false, true] as $bradford) {
        $reply = $this->sync->pull(2, bradford: $bradford)->assertOk();
        ($this->valid)($reply);
        $setting = Pull::changes($reply)[0];

        expect($setting)->toMatchArray([
            'seq' => 0, 'entity' => 'Setting', 'op' => 'I', 'version' => 3, 'companyId' => TillFixtures::COMPANY,
            'branchId' => '', 'registerId' => '',
            'entityId' => SyncRowIds::setting('company', TillFixtures::COMPANY, 'receipt.footer_text'),
        ])->and($setting['payload'])->toBe([
            'scope' => 'company', 'scopeId' => TillFixtures::COMPANY, 'key' => 'receipt.footer_text',
            'value' => 'Thank you for shopping at Kirkgate!', 'updatedAt' => '2026-09-29T10:00:00Z',
        ]);
    }

    // The same value again changes nothing; a new value is `U`; removing it is `D` (the till falls back).
    ($this->save)(SettingScope::Company, 'receipt.footer_text', 'Thank you for shopping at Kirkgate!');
    expect(Pull::changes($this->sync->pull(3)))->toBe([]);

    $this->travel(1)->minutes();
    ($this->save)(SettingScope::Company, 'receipt.footer_text', 'See you soon');
    ($this->save)(SettingScope::Company, 'till.refund_needs_manager', null);   // nothing to remove
    expect(Pull::summary($this->sync->pull(3)))->toBe([['Setting', 'U', 4]]);

    ($this->save)(SettingScope::Company, 'receipt.footer_text', null);
    $removed = Pull::changes($this->sync->pull(4, bradford: true));
    expect([$removed[0]['op'], $removed[0]['payload']['key']])->toBe(['D', 'receipt.footer_text']);
});

test('a branch setting goes to that shop only, addressed with its till branch id', function () {
    ($this->save)(SettingScope::Branch, 'till.refund_needs_manager', 'true', $this->sync->leeds);

    $leeds = Pull::changes($this->sync->pull(2));
    expect(Pull::changes($this->sync->pull(2, bradford: true)))->toBe([])
        ->and($leeds[0]['branchId'])->toBe(TillFixtures::LEEDS)
        ->and($leeds[0]['payload']['scopeId'])->toBe(TillFixtures::LEEDS)
        ->and($leeds[0]['entityId'])->toBe(SyncRowIds::setting('branch', TillFixtures::LEEDS, 'till.refund_needs_manager'));
});

test('replays push-request.settings.json: a shop\'s permission changes reach the other shop, never back to it', function () {
    $this->sync->push(TillFixtures::sample('push-request.settings.json'))->assertOk();

    // Leeds's branch setting is Leeds's own: nobody gets it. The permission grant and removal go to Bradford only.
    expect(Pull::changes($this->sync->pull(2)))->toBe([]);
    $bradford = $this->sync->pull(2, bradford: true);
    ($this->valid)($bradford);

    expect(array_map(fn ($c) => [$c['entity'], $c['op'], $c['payload']], Pull::changes($bradford)))->toEqualCanonicalizing([
        ['RolePermission', 'I', ['roleId' => '01K5T0Q8C4000000000000G002', 'permissionKey' => 'sale.refund']],
        ['RolePermission', 'D', ['roleId' => '01K5T0Q8C4000000000000G002', 'permissionKey' => 'sale.no_sale']],
    ]);
});

test('role permissions granted and taken away on the portal: I and D, never from the Owner role', function () {
    app(SetRolePermission::class)->handle($this->company, $this->role->id, 'business.apply_all_shops', true);
    expect(Pull::summary($this->sync->pull(2)))->toBe([['RolePermission', 'I', 3]]);

    app(SetRolePermission::class)->handle($this->company, $this->role->id, 'business.apply_all_shops', false);
    $taken = Pull::changes($this->sync->pull(3, bradford: true));
    expect([$taken[0]['op'], $taken[0]['entityId']])->toBe(['D', SyncRowIds::rolePermission($this->role->id, 'business.apply_all_shops')]);

    app(SetRolePermission::class)->handle($this->company, $this->role->id, 'business.apply_all_shops', true);
    expect(Pull::summary($this->sync->pull(4)))->toBe([['RolePermission', 'I', 5]]);

    expect(fn () => app(SetRolePermission::class)->handle($this->company, $this->owner->id, 'sale.refund', false))
        ->toThrow(ValidationException::class, 'Owner role always keeps every permission');
    expect(fn () => app(SetRolePermission::class)->handle($this->company, '01K5T0Q8C4000000000000G999', 'sale.refund', true))
        ->toThrow(ValidationException::class);
});

test('the deny-list never leaves the portal: device, sync and secret settings and register scope are refused', function (string $key) {
    expect(fn () => ($this->save)(SettingScope::Company, $key, 'x'))->toThrow(ValidationException::class);
})->with(['sync.hub_url', 'printers.receipt_printer', 'payments.dojo_api_key', 'backup.nightly_time', 'display.theme', 'till_ease.big_text', 'retention.last_vacuum_utc']);

test('a register-scope or deny-listed row that got stored anyway is never sent', function () {
    expect(fn () => ($this->save)(SettingScope::Register, 'receipt.footer_text', 'x'))->toThrow(ValidationException::class);

    foreach ([['register', 'receipt.footer_text'], ['company', 'sync.hub_url']] as $n => [$scope, $key]) {
        DB::table('till_settings')->insert([
            'id' => SyncRowIds::setting($scope, $this->company->id, $key), 'company_id' => $this->company->id, 'scope' => $scope,
            'scope_id' => $this->company->id, 'setting_key' => $key, 'value' => 'x', 'row_version' => 1, 'hub_version' => 10 + $n,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }
    DB::table('sync_hub_counters')->where('company_id', $this->company->id)->update(['last_version' => 11]);

    expect(Pull::changes($this->sync->pull(2)))->toBe([]);   // after the two roles
});

test('cash.count_on_close and till.keypad_price_in_pence are shared keys (settings-local-only.json), so they travel', function () {
    ($this->save)(SettingScope::Company, 'cash.count_on_close', 'true');
    ($this->save)(SettingScope::Company, 'till.keypad_price_in_pence', 'true');

    expect(collect(Pull::changes($this->sync->pull(2)))->pluck('payload.key')->all())->toBe(['cash.count_on_close', 'till.keypad_price_in_pence']);
});

test('a setting is kept to its own business: another business\'s shop cannot be named, its settings never reach here', function () {
    $other = Company::factory()->create();
    $shop = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);

    expect(fn () => ($this->save)(SettingScope::Branch, 'till.refund_needs_manager', 'true', $shop))->toThrow(ValidationException::class);

    app(SaveTillSetting::class)->handle($other, SettingScope::Company, null, 'receipt.footer_text', 'Theirs');
    expect(Pull::changes($this->sync->pull(2)))->toBe([]);
});
