<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Admin → Settings → Partners (owner only): partners, webhook URL and signing secret, API keys shown once.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Http::preventStrayRequests();
    $this->setupOnlyPlan();
    $this->actingAs($this->admin(), 'admin');
});

test('the owner adds a partner and sets its webhook URL', function () {
    $this->post(route('admin.settings.partners.store'), ['name' => 'Zivaro', 'webhook_url' => self::WEBHOOK_URL, 'is_active' => true])
        ->assertSessionHasNoErrors()->assertSessionHas('success');
    $partner = Partner::query()->sole();

    $this->put(route('admin.settings.partners.update', $partner), ['name' => 'Zivaro Ltd', 'webhook_url' => 'https://zivaro.test/hooks', 'is_active' => true])
        ->assertSessionHasNoErrors();

    expect($partner->refresh()->name)->toBe('Zivaro Ltd')->and($partner->webhook_url)->toBe('https://zivaro.test/hooks')
        ->and(AuditLog::query()->whereIn('action', ['partner.created', 'partner.updated'])->count())->toBe(2);

    $this->post(route('admin.settings.partners.store'), ['name' => '', 'webhook_url' => 'not a url'])->assertSessionHasErrors(['name', 'webhook_url']);
});

test('a webhook secret is shown once and stored encrypted', function () {
    $partner = $this->partner();

    $secret = $this->postJson(route('admin.settings.partners.webhook-secret', $partner))->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private')->json('secret');

    expect($secret)->toStartWith('whsec_')
        ->and($partner->refresh()->webhook_secret)->toBe($secret)
        ->and(DB::table('partners')->value('webhook_secret'))->not->toContain($secret)
        ->and(json_encode(DB::table('audit_logs')->get()))->not->toContain($secret);

    $this->get(route('admin.settings.partners.index'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('admin/settings/partners')
        ->where('partners.0.hasSecret', true)
        ->where('partners.0.secretEnding', substr($secret, -4))
        ->missing('partners.0.webhookSecret'));

    // Rotating replaces it at once.
    $next = $this->postJson(route('admin.settings.partners.webhook-secret', $partner))->assertOk()->json('secret');
    expect($next)->not->toBe($secret)->and($partner->refresh()->webhook_secret)->toBe($next);
});

test('an API key is shown once, stored as a hash and last 4, works, and stops when revoked', function () {
    $partner = $this->partner();

    $key = $this->postJson(route('admin.settings.partners.keys.store', $partner), ['name' => 'Live'])->assertOk()->json('key');
    $row = PartnerKey::query()->sole();

    expect($key)->toMatch('/^spk_[0-9a-z]{40}$/')
        ->and($row->key_last4)->toBe(substr($key, -4))
        ->and(json_encode([DB::table('partner_keys')->get(), DB::table('audit_logs')->get(), DB::table('partners')->get()]))->not->toContain($key);

    $this->get(route('admin.settings.partners.index'))->assertInertia(fn (Assert $page) => $page
        ->where('partners.0.keys.0.masked', 'spk_••••'.substr($key, -4))
        ->where('partners.0.keys.0.name', 'Live')
        ->where('partners.0.keys.0.revokedAt', null));

    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertOk();
    expect($row->refresh()->last_used_at)->not->toBeNull();

    $this->delete(route('admin.settings.partners.keys.destroy', [$partner, $row]))->assertSessionHas('success');
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertUnauthorized()->assertJsonPath('code', 'auth.key_revoked');
    $this->delete(route('admin.settings.partners.keys.destroy', [$partner, $row]))->assertSessionHasErrors('key');
});

test('a key is revoked only through its own partner', function () {
    $partner = $this->partner();
    $other = $this->partner('Other');
    $this->postJson(route('admin.settings.partners.keys.store', $other))->assertOk();
    $key = PartnerKey::query()->sole();

    $this->delete(route('admin.settings.partners.keys.destroy', [$partner, $key]))->assertNotFound();
    expect($key->refresh()->revoked_at)->toBeNull();
});

test('a deactivated partner\'s keys are refused', function () {
    [$partner, $key] = $this->partnerWithKey();

    $this->put(route('admin.settings.partners.update', $partner), ['name' => 'Zivaro', 'webhook_url' => self::WEBHOOK_URL, 'is_active' => false])->assertSessionHasNoErrors();

    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertUnauthorized()->assertJsonPath('code', 'auth.key_revoked');
});

test('only the owner manages partners', function (AdminRole $role) {
    $partner = $this->partner();
    $this->actingAs($this->admin($role), 'admin');

    $this->get(route('admin.settings.partners.index'))->assertForbidden();
    $this->post(route('admin.settings.partners.store'), ['name' => 'X'])->assertForbidden();
    $this->put(route('admin.settings.partners.update', $partner), ['name' => 'X'])->assertForbidden();
    $this->postJson(route('admin.settings.partners.webhook-secret', $partner))->assertForbidden();
    $this->postJson(route('admin.settings.partners.keys.store', $partner))->assertForbidden();
    expect(PartnerKey::query()->count())->toBe(0)->and($partner->refresh()->webhook_secret)->toBeNull();
})->with([AdminRole::Sales, AdminRole::Support, AdminRole::Accounts]);
