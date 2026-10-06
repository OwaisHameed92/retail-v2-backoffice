<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Actions\ResolveSyncConflict;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Sync\Enums\ConflictKind;
use App\Domain\TillData\Sync\Enums\ConflictResolution;
use App\Domain\TillData\Sync\Models\SyncConflict;
use App\Domain\TillData\Sync\OwnershipRules;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

/** Module 2.9B: settling sync_conflicts (contract v1.4.1 §8, §19.3, §19.4 test 5). */
beforeEach(function () {
    $this->sync = new SyncApiFixtures($this);
    $this->company = $this->sync->company;
    $this->travelTo('2026-09-29 10:00:00');
    $this->product = TillFixtures::sample('entities/Product.json');

    // 19.4 test 5: the same product changed on the portal (10:05) and at Leeds (10:02) before either synced.
    Pull::portalCreate($this->company, 'Product', $this->product);
    $this->travel(5)->minutes();
    Pull::portalUpdate($this->company, 'Product', $this->product['id'], ['name' => 'Portal name']);
    $this->leedsEdit = [...$this->product, 'name' => 'Shop name', 'rowVersion' => 2, 'updatedAt' => '2026-09-29T10:02:00Z'];
    $this->sync->push([TillFixtures::envelope('Product', $this->leedsEdit, 1, ['op' => 'U', 'at' => '2026-09-29T10:02:00Z'])])->assertOk();
    $this->conflict = SyncConflict::withoutCompanyScope()->sole();
    $this->resolve = fn (ConflictResolution $r, ?SyncConflict $c = null) => app(ResolveSyncConflict::class)->handle($c ?? $this->conflict, $r, User::factory()->create(), 'Checked with the shop');
    $this->name = fn () => DB::table('products')->where('id', $this->product['id'])->value('name');
});

test('19.4 test 5: one conflict, the portal\'s row wins and is sent to the shop again', function () {
    expect($this->conflict->kind)->toBe(ConflictKind::HubEditNewer)
        ->and($this->conflict->branch_id)->toBe($this->sync->leeds->id)
        ->and(($this->name)())->toBe('Portal name');

    $leeds = collect(Pull::changes($this->sync->pull(1)))->where('entity', 'Product')->values();
    expect($leeds)->toHaveCount(1)->and($leeds[0]['payload']['name'])->toBe('Portal name');
});

test('keep the portal\'s version: nothing changes, the conflict is closed with who and why, and audited', function () {
    $resolved = ($this->resolve)(ConflictResolution::KeepPortal);

    expect($resolved->status)->toBe('resolved')
        ->and($resolved->resolution)->toBe('keepPortal')
        ->and($resolved->resolution_note)->toBe('Checked with the shop')
        ->and($resolved->resolved_by)->not->toBeNull()
        ->and(($this->name)())->toBe('Portal name')
        ->and(AuditLog::query()->where('action', 'sync_conflict.resolved')->where('company_id', $this->company->id)->count())->toBe(1);
});

test('use the shop\'s version: written as a portal edit and sent to every till, the shop\'s own included', function () {
    $since = $this->sync->pull(0)->json('highestVersion');
    ($this->resolve)(ConflictResolution::UseTill);

    expect(($this->name)())->toBe('Shop name');

    foreach ([false, true] as $bradford) {
        $product = collect(Pull::changes($this->sync->pull($since, bradford: $bradford)))->firstWhere('entity', 'Product');
        expect($product['payload']['name'])->toBe('Shop name');
    }
});

test('a customer\'s ledger figures are never taken from the shop\'s version', function () {
    $customer = TillFixtures::sample('entities/Customer.json');
    Pull::portalCreate($this->company, 'Customer', $customer);
    $this->travel(5)->minutes();
    Pull::portalUpdate($this->company, 'Customer', $customer['id'], ['name' => 'Portal']);
    $edit = [...$customer, 'name' => 'Shop', 'balance' => 50.0, 'points' => 999, 'rowVersion' => 2];
    $this->sync->push([TillFixtures::envelope('Customer', $edit, 2, ['op' => 'U', 'at' => '2026-09-29T10:06:00Z'])])->assertOk();

    ($this->resolve)(ConflictResolution::UseTill, SyncConflict::withoutCompanyScope()->where('entity', 'Customer')->sole());

    $row = DB::table('customers')->where('id', $customer['id'])->first();
    expect($row->name)->toBe('Shop')->and((int) $row->points)->toBe(0)
        ->and(OwnershipRules::derivedColumns('Customer'))->toBe(['balance', 'pending_points', 'points']);
});

test('a historic record or a deleted shop can only be acknowledged; a conflict is settled once', function () {
    $immutable = Pull::conflict($this->company, ['branch_id' => $this->sync->leeds->id]);

    expect(fn () => ($this->resolve)(ConflictResolution::UseTill, $immutable))->toThrow(ValidationException::class, 'does not apply')
        ->and(($this->resolve)(ConflictResolution::Acknowledged, $immutable)->resolution)->toBe('acknowledged')
        ->and(fn () => ($this->resolve)(ConflictResolution::Acknowledged, $immutable->fresh()))->toThrow(ValidationException::class, 'already been resolved');

    expect(fn () => ($this->resolve)(ConflictResolution::Acknowledged))->toThrow(ValidationException::class);
});

test('ownership rules match samples/ownership.json (relayed, hubDrafted, derivedColumns)', function () {
    $ownership = TillFixtures::sample('ownership.json');

    expect(OwnershipRules::RELAYED)->toEqualCanonicalizing(array_keys($ownership['relayed']))
        ->and(OwnershipRules::DRAFTED)->toEqualCanonicalizing(array_keys($ownership['hubDrafted']))
        ->and(OwnershipRules::DERIVED_COLUMNS)->toEqual(array_map(
            fn (array $fields) => array_map(fn (string $f) => strtolower((string) preg_replace('/[A-Z]/', '_$0', $f)), $fields),
            $ownership['derivedColumns'],
        ));
});

test('runs in the conflict\'s own company, even when another company is current', function () {
    $other = Company::factory()->create();

    app(CurrentCompany::class)->runAs($other, fn () => ($this->resolve)(ConflictResolution::UseTill));

    expect(($this->name)())->toBe('Shop name')
        ->and(Product::withoutCompanyScope()->where('company_id', $other->id)->count())->toBe(0);
});
