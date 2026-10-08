<?php

use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Sync\PullTestHelpers as Pull;
use Tests\Feature\Sync\SyncApiFixtures;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillData\TillFixtures;
use Tests\Support\ContractSchema;
use Tests\Support\PakPosContract;

uses(TenantTestHelpers::class);

/*
 * Pak POS pack 2026-10-07 (owner): Branch `nation` is "Pakistan" for every Pakistan shop, a free string on the wire.
 * PK (`country-pk`): portal-made and edited shops store it and their tills pull it; a till's push of the old default
 * does not overwrite it; `branches:pakistan-nation` moves shops still at the column default. GB golden: the four UK
 * nations exactly as before, "Pakistan" refused from forms and ignored from tills, `england` pulled as always.
 */

test('GB golden: the forms offer and accept the four UK nations only; a shop keeps the one picked', function () {
    $company = $this->tenant('Kirkgate Stores', 1, 'LDS');

    expect(array_column(Nation::options(), 'value'))->toBe(['england', 'scotland', 'wales', 'northernIreland'])
        ->and($this->branchOf($company, 'LDS')->nation)->toBe(Nation::England);

    $admin = $this->admin();
    $this->actingAs($admin, 'admin')->post("/admin/tenants/{$company->id}/branches", ['code' => 'GLA', 'name' => 'Glasgow', 'nation' => 'scotland', 'tills' => 1])
        ->assertSessionHas('success');
    $this->actingAs($admin, 'admin')->post("/admin/tenants/{$company->id}/branches", ['code' => 'KHI', 'name' => 'Karachi', 'nation' => 'Pakistan', 'tills' => 1])
        ->assertSessionHasErrors(['nation' => 'The selected nation is invalid.']);

    expect($this->branchOf($company, 'GLA')->nation)->toBe(Nation::Scotland)
        ->and(Branch::withoutCompanyScope()->where('code', 'KHI')->exists())->toBeFalse();

    $this->artisan('branches:pakistan-nation')->expectsOutputToContain('Only on the Pakistan instance')->assertFailed();
    expect(Branch::withoutCompanyScope()->where('nation', 'Pakistan')->count())->toBe(0);
});

test('GB golden: a till\'s pushed nation is kept only when it is a UK nation; the pull sends the stored value', function () {
    $sync = new SyncApiFixtures($this);
    $this->travelTo('2026-10-08 10:00:00');
    $sync->bradford->forceFill(['name' => 'Bradford Market Street'])->save();
    $row = Pull::changes($sync->pull(0, bradford: true))[0]['payload'];

    expect($row['nation'])->toBe('england')
        ->and(ContractSchema::errors($row, 'schemas/entities/Branch.schema.json'))->toBe([]);

    foreach ([['Pakistan', 'england', 8], ['wales', 'wales', 9]] as [$sent, $stored, $version]) {
        $sync->push([TillFixtures::envelope('Branch', [...$row, 'nation' => $sent, 'rowVersion' => $version, 'updatedAt' => "2026-10-08T10:0{$version}:00Z"], $version, ['branchId' => TillFixtures::BRADFORD, 'op' => 'U'])], bradford: true)->assertOk();
        expect(DB::table('branches')->where('id', $sync->bradford->id)->value('nation'))->toBe($stored);
    }
});

test('PK: portal-made and edited shops carry "Pakistan" (the hidden field sends the default) and their till pulls it', function () {
    CountryModulesFixtures::pakistan();
    $company = $this->tenant('Lahore Mart', 1, 'LHR');
    $admin = $this->admin();

    expect($this->branchOf($company, 'LHR')->nation)->toBe(Nation::Pakistan)
        ->and(DB::table('branches')->where('code', 'LHR')->value('nation'))->toBe('Pakistan')
        ->and(Nation::options())->toBe([]);

    $this->actingAs($admin, 'admin')->post("/admin/tenants/{$company->id}/branches", ['code' => 'KHI', 'name' => 'Saddar', 'nation' => 'england', 'tills' => 1, 'town' => 'Karachi'])
        ->assertSessionHas('success');
    expect($this->branchOf($company, 'KHI')->nation)->toBe(Nation::Pakistan);

    // A shop made before (column default) becomes Pakistan when it is next saved on the portal.
    $old = $this->branchOf($company, 'KHI');
    DB::table('branches')->where('id', $old->id)->update(['nation' => 'england']);
    $this->actingAs($admin, 'admin')->put("/admin/tenants/{$company->id}/branches/{$old->id}", ['code' => 'KHI', 'name' => 'Saddar Bazaar', 'nation' => 'england', 'town' => 'Karachi'])
        ->assertSessionHas('success');
    expect($old->fresh()->nation)->toBe(Nation::Pakistan);
})->group('country-pk');

test('PK: the pull sends "Pakistan" (valid for the Pak POS Branch schema); a till\'s old default never overwrites it', function () {
    $sync = new SyncApiFixtures($this);
    CountryModulesFixtures::pakistan();
    $this->travelTo('2026-10-08 10:00:00');
    $sync->bradford->forceFill(['nation' => Nation::Pakistan])->save();
    $row = Pull::changes($sync->pull(0, bradford: true))[0]['payload'];

    expect($row['nation'])->toBe('Pakistan')
        ->and(PakPosContract::errors($row, 'schemas/entities/Branch.schema.json'))->toBe([]);

    foreach (['England', 'england', 'Pakistan'] as $i => $sent) {
        $phone = "0300 123456{$i}";
        $sync->push([TillFixtures::envelope('Branch', [...$row, 'nation' => $sent, 'phone' => $phone, 'rowVersion' => 8 + $i, 'updatedAt' => '2026-10-08T10:0'.($i + 5).':00Z'], 8 + $i, ['branchId' => TillFixtures::BRADFORD, 'op' => 'U'])], bradford: true)->assertOk();
        expect(DB::table('branches')->where('id', $sync->bradford->id)->first(['nation', 'phone']))->toEqual((object) ['nation' => 'Pakistan', 'phone' => $phone]);
    }
})->group('country-pk');

test('PK: branches:pakistan-nation lists, then moves, only the shops still at the default; audited, sent to the till, idempotent', function () {
    $sync = new SyncApiFixtures($this);   // Leeds and Bradford, made before the instance's nation was known
    $other = $this->tenant('Karachi Stores', 1, 'KST');
    $this->branchOf($other, 'KST')->forceFill(['nation' => Nation::Wales])->saveQuietly(); // not the default: left alone
    CountryModulesFixtures::pakistan();

    $this->artisan('branches:pakistan-nation', ['--dry-run' => true])
        ->expectsOutputToContain('Would set 2 shops to Pakistan.')->assertSuccessful();
    expect(DB::table('branches')->where('nation', 'Pakistan')->count())->toBe(0);

    $this->artisan('branches:pakistan-nation')->expectsOutputToContain('Set 2 shops to Pakistan.')->assertSuccessful();

    expect($sync->leeds->fresh()->nation)->toBe(Nation::Pakistan)
        ->and($sync->bradford->fresh()->nation)->toBe(Nation::Pakistan)
        ->and($this->branchOf($other, 'KST')->nation)->toBe(Nation::Wales)
        ->and(AuditLog::query()->where('action', 'branch.updated')->where('subject_id', $sync->leeds->id)->exists())->toBeTrue()
        ->and(Pull::changes($sync->pull(0, bradford: true))[0]['payload']['nation'])->toBe('Pakistan');

    $this->artisan('branches:pakistan-nation')->expectsOutputToContain('Nothing to change.')->assertSuccessful();
})->group('country-pk');
