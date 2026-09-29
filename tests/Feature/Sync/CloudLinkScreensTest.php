<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Licensing\Models\LocalLicenceKeyRefusal;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Sync\Enums\CloudUploadStatus;
use App\Domain\Sync\Models\CloudUpload;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

/** Module 2.8: the admin "Cloud link" screen and the tenant page's Cloud link tab. */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-07 12:00', 'UTC'));

    $this->khan = $this->licensedTenant('Khan Mini Mart', 2, 'LDS');
    $this->patel = $this->licensedTenant('Patel News', 1, 'PNW');
    $upload = fn ($company, $code, array $extra) => CloudUpload::withoutCompanyScope()->create([
        'company_id' => $company->id, 'branch_id' => $this->branchOf($company, $code)->id, 'install_id' => '01K5T0Q8C4000000000000H00'.($code === 'LDS' ? '1' : '2'),
        'install_code' => 'AC4F-3FHG', 'device_name' => "{$code}-TILL", 'till_company_id' => '01K5T0Q8C4000000000000C001',
        'till_branch_id' => '01K5T0Q8C4000000000000B001', 'expected_rows' => 200, 'id_mapping' => ['company' => ['action' => 'adopted'], 'branch' => ['action' => 'adopted']], ...$extra,
    ]);
    $this->khanMove = $upload($this->khan, 'LDS', ['received_rows' => 50]);
    $this->patelMove = $upload($this->patel, 'PNW', ['received_rows' => 200, 'status' => CloudUploadStatus::Complete, 'completed_at' => now()]);
    $key = fn (?string $companyId, string $licenceId, array $extra = []) => LocalLicenceKey::query()->create([
        'licence_id' => $licenceId, 'install_code' => 'Q3EG-1RNC', 'kid' => 'k76b44696', 'token_sha256' => str_repeat('ab', 32),
        'company_id' => $companyId, 'business_name' => 'Local shop', 'reported_via' => 'report', 'first_seen_at' => now(),
        'last_reported_at' => now(), 'expires_at' => now()->addYear(), ...$extra,
    ]);
    $this->khanKey = $key($this->khan->id, '01K5M1GR8T000000000000Y004', ['refused_count' => 1]);
    $this->strangerKey = $key(null, '01K5M1GR8T000000000000Y005');
});

test('behind auth:admin, can:tenants.view to see and can:licences.manage to clear, like its nav item', function () {
    $this->get('/admin/cloud-link')->assertRedirect(route('admin.login'));
    $this->actingAs($this->ownerOf($this->khan))->get('/admin/cloud-link')->assertRedirect(route('admin.login'));

    expect(Route::getRoutes()->getByName('admin.cloud-link.index')->gatherMiddleware())->toContain('auth:admin', 'can:'.AdminRole::TENANTS_VIEW)
        ->and(Route::getRoutes()->getByName('admin.cloud-link.local-keys.destroy')->gatherMiddleware())->toContain('auth:admin', 'can:'.AdminRole::LICENCES_MANAGE)
        ->and(preg_match("/title: 'Cloud link',[^}]*route: 'admin\\.cloud-link\\.index',[^}]*ability: 'tenants\\.view'/s", (string) file_get_contents(resource_path('js/components/admin/admin-nav.ts'))))->toBe(1);

    // Sales and accounts may look, not clear.
    foreach ([AdminRole::Sales, AdminRole::Accounts] as $role) {
        $this->actingAs($this->admin($role), 'admin')->get('/admin/cloud-link')->assertOk()->assertInertia(fn (AssertableInertia $page) => $page->where('canClear', false));
        $this->actingAs($this->admin($role), 'admin')->delete(route('admin.cloud-link.local-keys.destroy', $this->khanKey), ['reason' => 'Test'])->assertForbidden();
    }

    expect(LocalLicenceKey::query()->count())->toBe(2);
});

test('moves across every business with progress, the register with its refusals', function () {
    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin/cloud-link')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/cloud-link/index')
            ->where('view', 'moves')->where('canClear', true)->where('keys', null)
            ->where('summary', ['uploading' => 1, 'complete' => 1, 'localKeys' => 2, 'refused' => 1])
            ->has('moves.data', 2)
            ->where('moves.data', fn ($rows) => collect($rows)->firstWhere('id', $this->khanMove->id)['percent'] === 25
                && collect($rows)->firstWhere('id', $this->patelMove->id)['status'] === 'complete'));

    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin/cloud-link?view=refused')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('moves', null)->has('keys.data', 1)
            ->where('keys.data.0.licenceId', '01K5M1GR8T000000000000Y004')
            ->where('keys.data.0.tokenHashStart', 'abababababab')
            ->where('keys.data.0.company.name', 'Khan Mini Mart'));

    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin/cloud-link?view=keys&search=Y005')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('keys.data', 1)->where('keys.data.0.company', null));
});

test('clearing a record needs a reason, removes its refusals and is audited', function () {
    LocalLicenceKeyRefusal::query()->create(['local_licence_key_id' => $this->khanKey->id, 'install_code' => 'BK7Q-2MXD', 'token_sha256' => str_repeat('cd', 32), 'refused_at' => now()]);
    $admin = $this->admin(AdminRole::Support);

    $this->actingAs($admin, 'admin')->delete(route('admin.cloud-link.local-keys.destroy', $this->khanKey))->assertSessionHasErrors('reason');
    $this->actingAs($admin, 'admin')->delete(route('admin.cloud-link.local-keys.destroy', $this->khanKey), ['reason' => 'Dealer moved the key to the new PC'])
        ->assertRedirect()->assertSessionHas('success');

    expect(LocalLicenceKey::query()->pluck('id')->all())->toBe([$this->strangerKey->id])
        ->and(LocalLicenceKeyRefusal::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'local_key.cleared')->sole()->company_id)->toBe($this->khan->id);
});

test('the tenant page\'s Cloud link tab shows only that business\'s moves and keys', function () {
    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get(route('admin.tenants.show', $this->khan))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('cloudLink.moves', 1)->has('cloudLink.keys', 1)
            ->where('cloudLink.moves.0.id', $this->khanMove->id)
            ->where('cloudLink.keys.0.id', $this->khanKey->id));

    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get(route('admin.tenants.show', $this->patel))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->has('cloudLink.moves', 1)->has('cloudLink.keys', 0)
            ->where('cloudLink.moves.0.id', $this->patelMove->id));
});
