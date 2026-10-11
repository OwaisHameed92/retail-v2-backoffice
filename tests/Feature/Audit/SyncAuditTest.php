<?php

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Sync\Enums\ChangeOutcome;
use App\Domain\TillData\Sync\HubVersions;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\TillData\TillFixtures;

uses()->group('audit-sync');

/*
 * Sync audit 2026-10-11 (docs/audit/2026-10-11-sync.md). Each test proves one finding (SYNC-n) and FAILS until it is
 * fixed: the expectation is the correct behaviour. Store-level tests call ApplySyncChanges as the push endpoint does.
 */

beforeEach(function () {
    [$this->company, $this->leeds, $this->bradford] = TillFixtures::tenant();
    $this->apply = fn (array $changes, $branch = null) => TillFixtures::apply($this->company, $branch ?? $this->leeds, $changes);
});

it('SYNC-1: another shop of the business cannot take over (re-branch) a completed sale of Leeds', function () {
    $sale = TillFixtures::sample('entities/Sale.json');
    ($this->apply)([TillFixtures::envelope('Sale', $sale, 1)]);

    // Bradford's till pushes the same sale id, stamped with its own branch and till and a higher version.
    $hijack = [...$sale, 'branchId' => TillFixtures::BRADFORD, 'registerId' => TillFixtures::BRADFORD_TILL, 'rowVersion' => 9, 'total' => 0.01];
    $result = ($this->apply)([TillFixtures::envelope('Sale', $hijack, 1)], $this->bradford);

    $stored = DB::table('sales')->where('id', $sale['id'])->first();

    // Expected: refused (the row belongs to Leeds). Actual: accepted and moved to Bradford's till.
    expect($result->count(ChangeOutcome::Applied))->toBe(0)
        ->and($stored->branch_id)->toBe(TillFixtures::LEEDS)
        ->and($stored->register_id)->toBe(TillFixtures::TILL_1);
});

it('SYNC-2: a seq the ledger already holds but carrying a different row is not silently dropped (restored backup / new PC)', function () {
    $sale = TillFixtures::sample('entities/Sale.json');
    ($this->apply)([TillFixtures::envelope('Sale', $sale, 1001)]);

    // The main till moved to a new PC and restored last night's backup: its ChangeLog hands out seq 1001 again,
    // this time for a new sale.
    $newSale = [...$sale, 'id' => '01K5VB000000000SR001000999', 'number' => 999, 'receiptNumber' => 'LDS-01-000999'];
    $result = ($this->apply)([TillFixtures::envelope('Sale', $newSale, 1001)]);

    // Expected: the new sale is stored (or refused so the till reports it). Actual: acknowledged as a duplicate,
    // never stored, and the till marks it sent.
    expect(DB::table('sales')->where('id', $newSale['id'])->exists())->toBeTrue()
        ->and($result->count(ChangeOutcome::Duplicate))->toBe(0);
});

it('SYNC-7: a company detail changed at the second shop\'s till is not silently dropped because the first shop\'s till counts higher', function () {
    $company = fn (string $vat, string $at) => Pull::payload('Company', TillFixtures::COMPANY, [
        'name' => 'Kirkgate Convenience', 'vatNumber' => $vat, 'createdAt' => '2026-09-01T08:00:00Z', 'updatedAt' => $at,
    ]);

    // Leeds' main till has edited its Company row 50 times; Bradford's main till keeps its own counter (3).
    ($this->apply)([TillFixtures::envelope('Company', [...$company('GB111111111', '2026-10-01T09:00:00Z'), 'rowVersion' => 50], 1, ['version' => 50, 'at' => '2026-10-01T09:00:00Z'])]);
    $later = ($this->apply)([TillFixtures::envelope('Company', [...$company('GB222222222', '2026-10-02T09:00:00Z'), 'rowVersion' => 3], 1, ['version' => 3, 'at' => '2026-10-02T09:00:00Z'])], $this->bradford);

    // Expected: the later edit is applied (or kept as a conflict for a person). Actual: Stale, silently ignored, until
    // Bradford's till has counted past 50.
    expect($later->count(ChangeOutcome::Stale))->toBe(0)
        ->and(DB::table('companies')->where('id', TillFixtures::COMPANY)->value('vat_number'))->toBe('GB222222222');
});

it('SYNC-9: an unknown entity whose name is longer than the ledger column never crashes the whole push (MySQL)', function () {
    $sync = new SyncApiFixtures($this);
    $product = TillFixtures::sample('entities/Product.json');

    // A newer till's entity (kept raw, §18.8) with a long type name. SQLite does not enforce varchar(64); MySQL strict
    // mode throws in UnknownEntityRows, outside ChunkApplier's per-row fallback → 500 for the whole batch, every retry.
    $response = $sync->push([TillFixtures::envelope('Product', $product, 1, ['entity' => 'PharmacyControlledDrugRegisterWitnessSignatureAuditTrailEntryV2'.str_repeat('X', 10)])]);

    expect($response->status())->not->toBe(500);
});

it('SYNC-10: a role permission of one business never blocks the same permission of another business', function () {
    $other = Company::factory()->create(['name' => 'Other Business']);
    $otherBranch = Branch::factory()->forCompany($other)->create(['code' => 'OTH']);
    $grant = fn (string $companyId, string $branchId) => [
        'seq' => 7, 'entity' => 'RolePermission', 'entityId' => '878P6SA19AH0CCWX77Q8BN37VD', 'op' => 'I', 'version' => 7,
        'companyId' => $companyId, 'branchId' => '', 'registerId' => '', 'at' => '2026-09-23T09:53:12Z',
        // The same role id in both businesses (a till's seeded system role, if seeded with a fixed id).
        'payload' => ['roleId' => '01K5T0Q8C4000000000000G002', 'permissionKey' => 'sale.refund'],
    ];

    ($this->apply)([$grant(TillFixtures::COMPANY, TillFixtures::LEEDS)]);
    $result = TillFixtures::apply($other, $otherBranch, [$grant($other->id, $otherBranch->id)]);

    // Expected: stored for the other business too. Actual: rejected entity.id_taken (the derived id ignores the
    // business), which stops that till's push queue at this row for good.
    expect($result->rejected)->toBe([])
        ->and(DB::table('till_role_permissions')->where('company_id', $other->id)->count())->toBe(1);
});

it('SYNC-8: a till delete without a payload of a portal-owned row obeys the portal-wins rule and reaches the other shops', function () {
    $product = TillFixtures::sample('entities/Product.json');
    ($this->apply)([TillFixtures::envelope('Product', $product, 1)]);
    app(HubVersions::class)->stampPending($this->company->id);

    // The portal edits the product after the till's (late) delete was made.
    app(CurrentCompany::class)->runAs($this->company, fn () => Product::findOrFail($product['id'])->forceFill(['sell_price' => '1.60'])->save());
    app(HubVersions::class)->stampPending($this->company->id);

    $delete = TillFixtures::envelope('Product', $product, 2, ['op' => 'D', 'payload' => null, 'version' => 2, 'at' => '2026-09-01T08:00:00Z']);
    $result = ($this->apply)([$delete]);
    $stored = DB::table('products')->where('id', $product['id'])->first();

    // Expected (as for the same delete with its payload, §19.3): a conflict, the portal's row kept. Actual: applied,
    // the product is soft-deleted on the portal and, with hub_version untouched, no other shop is ever told.
    expect($result->count(ChangeOutcome::Applied))->toBe(0)
        ->and($stored->deleted_at)->toBeNull();
});
