<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Sync\Enums\SyncKeySource;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\SyncKeySecret;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class);

/** Module 2.1: the admin "Sync key" panel of a branch (licences.manage). */
beforeEach(function () {
    Mail::fake();
    $this->company = $this->licensedTenant();
    $this->branch = $this->branchOf($this->company);
    $this->base = "/admin/tenants/{$this->company->id}/branches/{$this->branch->id}/sync-key";
});

test('generate shows the key once; only its hash and last 4 are kept', function () {
    $admin = $this->admin(AdminRole::Support);
    $this->actingAs($admin, 'admin');

    $reply = $this->postJson($this->base)->assertOk()->assertHeader('Cache-Control', 'no-store, private')
        ->assertJsonPath('branchName', $this->branch->name);
    $plain = (string) $reply->json('key');
    $key = SyncKey::withoutCompanyScope()->sole();

    expect(SyncKeySecret::looksValid($plain))->toBeTrue()
        ->and($key->key_hash)->toBe(SyncKeySecret::hash($plain))
        ->and($key->source)->toBe(SyncKeySource::Admin)
        ->and($key->created_by)->toBe($admin->id)
        ->and($key->delivered_install_id)->toBeNull()
        ->and(json_encode(DB::table('sync_keys')->get()))->not->toContain(SyncKeySecret::canonical($plain))
        ->and(AuditLog::query()->where('action', 'sync_key.issued')->sole()->after)->toMatchArray(['key_last4' => substr($plain, -4), 'source' => 'admin']);

    // The tenant page never carries the key, only its mask and state.
    $this->get("/admin/tenants/{$this->company->id}")->assertInertia(fn (Assert $page) => $page
        ->where('branches.0.syncKey.status', 'active')
        ->where('branches.0.syncKey.maskedKey', SyncKeySecret::mask(substr($plain, -4)))
        ->where('branches.0.syncKey.source', 'admin')
        ->where('branches.0.syncKey.cloudSync', false));
    expect($this->get("/admin/tenants/{$this->company->id}")->getContent())->not->toContain(substr(SyncKeySecret::canonical($plain), 3, 12));
});

test('rotate replaces the key (the old one keeps its grace), send-to-till flags it, revoke stops them all', function () {
    $this->actingAs($this->admin(), 'admin');
    $first = $this->postJson($this->base)->json('key');
    $second = $this->postJson($this->base)->json('key');

    expect($second)->not->toBe($first)
        ->and(SyncKey::withoutCompanyScope()->current()->sole()->key_hash)->toBe(SyncKeySecret::hash($second))
        ->and(SyncKey::withoutCompanyScope()->whereNotNull('replaced_at')->sole()->key_hash)->toBe(SyncKeySecret::hash($first));

    $this->post("{$this->base}/rotate")->assertRedirect()->assertSessionHas('success');
    expect(SyncKey::withoutCompanyScope()->current()->sole()->rotate_requested_at)->not->toBeNull();

    $this->delete($this->base)->assertRedirect()->assertSessionHas('success');
    expect(SyncKey::withoutCompanyScope()->whereNull('revoked_at')->count())->toBe(0);
    $this->get("/admin/tenants/{$this->company->id}")->assertInertia(fn (Assert $page) => $page->where('branches.0.syncKey.status', 'revoked'));

    // Nothing left to revoke or rotate.
    $this->delete($this->base)->assertSessionHasErrors('sync_key');
    $this->post("{$this->base}/rotate")->assertSessionHasErrors('sync_key');
});

test('only licences.manage admins may generate, rotate or revoke', function (AdminRole $role) {
    $this->actingAs($this->admin($role), 'admin');

    $this->postJson($this->base)->assertForbidden();
    $this->post("{$this->base}/rotate")->assertForbidden();
    $this->delete($this->base)->assertForbidden();
    expect(SyncKey::withoutCompanyScope()->count())->toBe(0);
})->with([AdminRole::Sales, AdminRole::Accounts]);

test('guests are sent to the admin login', function () {
    $this->post($this->base)->assertRedirect();
    expect(SyncKey::withoutCompanyScope()->count())->toBe(0);
});

test('a branch of another business cannot be reached through this business', function () {
    $this->actingAs($this->admin(), 'admin');
    $other = $this->licensedTenant('Corner Shop', 1, 'CRN');
    $otherBranch = $this->branchOf($other, 'CRN');

    $this->postJson("/admin/tenants/{$this->company->id}/branches/{$otherBranch->id}/sync-key")->assertNotFound();
    $this->delete("/admin/tenants/{$this->company->id}/branches/{$otherBranch->id}/sync-key")->assertNotFound();
    expect(SyncKey::withoutCompanyScope()->count())->toBe(0);
});
