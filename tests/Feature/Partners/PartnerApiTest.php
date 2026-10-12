<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Mail\Mailables\AdminPartnerRequestMail;
use App\Domain\Partners\Actions\RevokePartnerKey;
use App\Domain\Partners\Actions\SavePartner;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Partner onboarding API v1 (docs/partner-api.md): Bearer partner key, PUT idempotent by externalId, GET, cancel,
 * plans, the error envelope, the per-key rate limit and the audit of every call.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake(['zivaro.test/*' => Http::response(['ok' => true])]);
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
    $this->standardPlan();
    $this->setupOnlyPlan();
});

test('a new deal lands as a pending request and never creates a business', function () {
    [$partner, $key] = $this->partnerWithKey();

    $this->putDeal($key, $this->dealBody())->assertCreated()
        ->assertExactJson([
            'externalId' => 'zivaro-deal-42',
            'status' => 'pendingReview',
            'tenantId' => null,
            'businessName' => 'Istanbul Market',
            'tills' => 2,
            'setupFee' => null,
            'tillsActivated' => 0,
            'firstActivatedAt' => null,
            'adminUrl' => null,
            'rejectedReason' => null,
            'cancelledReason' => null,
            'partnerCancelled' => false,
            'createdAt' => '2026-10-12T09:00:00.000Z',
            'updatedAt' => '2026-10-12T09:00:00.000Z',
        ]);

    $request = $this->requestFor();
    expect($request->partner_id)->toBe($partner->id)
        ->and($request->status)->toBe(PartnerRequestStatus::PendingReview)
        ->and($request->seller_email)->toBe('ali@switchandsave.co.uk')
        ->and($request->payload['shops'][0]['postcode'])->toBe('B1 1AA')
        ->and($request->payload['setupFeePerTill'])->toBe('750.00')
        ->and(Company::query()->count())->toBe(0)
        ->and(PartnerWebhookEvent::query()->count())->toBe(0);
    // Nothing to the business; only the staff alert (owner 2026-10-12, PartnerStaffAlertTest).
    Mail::assertQueuedCount(1);
    Mail::assertQueued(AdminPartnerRequestMail::class);
});

test('the same body again is the same request, unchanged', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->travel(5)->minutes();

    // Formatting differences (case, spacing, numbers as numbers) are still the same deal.
    $this->putDeal($key, $this->dealBody(['shops.0.postcode' => 'b1 1aa', 'setupFeePerTill' => 750, 'owner.email' => 'Sandra@Example.co.uk']))
        ->assertOk()->assertJsonPath('updatedAt', '2026-10-12T09:00:00.000Z');

    expect(PartnerTenantRequest::query()->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'partner_request.updated')->count())->toBe(0);
});

test('a changed body while pending updates the request', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->travel(5)->minutes();

    $this->putDeal($key, $this->dealBody(['shops.0.tills' => 3, 'business.name' => 'Istanbul Market Express']))
        ->assertOk()
        ->assertJsonPath('tills', 3)
        ->assertJsonPath('businessName', 'Istanbul Market Express')
        ->assertJsonPath('updatedAt', '2026-10-12T09:05:00.000Z');

    expect($this->requestFor()->payload['shops'][0]['tills'])->toBe(3)
        ->and(AuditLog::query()->where('action', 'partner_request.updated')->count())->toBe(1);
});

test('after approval a changed body is refused with 409 and the same body is still 200', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->approveAs($this->requestFor())->assertRedirect();

    $this->putDeal($key, $this->dealBody(['shops.0.tills' => 5]))
        ->assertStatus(409)
        ->assertJsonPath('code', 'partner.request_locked')
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);

    $this->putDeal($key, $this->dealBody())->assertOk()->assertJsonPath('status', 'approved');
    expect($this->requestFor()->payload['shops'][0]['tills'])->toBe(2);
});

test('a rejected request sent again goes back to review with the new body', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $admin = $this->admin();
    $this->actingAs($admin, 'admin')->post(route('admin.partner-requests.reject', $this->requestFor()), ['reason' => 'Add the VAT number'])->assertRedirect();
    $this->travel(1)->minutes();

    $this->putDeal($key, $this->dealBody(['business.vatNumber' => 'GB123456789']))->assertOk()
        ->assertJsonPath('status', 'pendingReview')
        ->assertJsonPath('rejectedReason', null)
        ->assertJsonPath('updatedAt', '2026-10-12T09:01:00.000Z');

    expect($this->requestFor()->payload['business']['vatNumber'])->toBe('GB123456789')
        ->and(AuditLog::query()->where('action', 'partner_request.reopened')->count())->toBe(1);

    // Rejected again: a second tenant.rejected event (each rejection is told once).
    $this->actingAs($admin, 'admin')->post(route('admin.partner-requests.reject', $this->requestFor()), ['reason' => 'Still a duplicate'])->assertRedirect();
    expect(PartnerWebhookEvent::query()->where('type', 'tenant.rejected')->count())->toBe(2);

    // The same body again also reopens it.
    $this->putDeal($key, $this->dealBody(['business.vatNumber' => 'GB123456789']))->assertOk()->assertJsonPath('status', 'pendingReview');
});

test('a cancelled request is closed: a changed body is 409 partner.request_closed', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->cancelDeal($key)->assertOk();

    $this->putDeal($key, $this->dealBody(['notes' => 'Changed']))->assertStatus(409)->assertJsonPath('code', 'partner.request_closed');
    $this->putDeal($key, $this->dealBody())->assertOk()->assertJsonPath('status', 'cancelled');
});

test('GET returns the request; another partner\'s or an unknown externalId is 404', function () {
    [, $key] = $this->partnerWithKey();
    [, $otherKey] = $this->partnerWithKey('Other reseller');
    $this->putDeal($key, $this->dealBody())->assertCreated();

    $this->getDeal($key)->assertOk()->assertJsonPath('externalId', 'zivaro-deal-42')->assertJsonPath('status', 'pendingReview');
    $this->getDeal($otherKey)->assertNotFound()->assertJsonPath('code', 'partner.request_not_found');
    $this->getDeal($key, 'zivaro-deal-999')->assertNotFound()->assertJsonPath('code', 'partner.request_not_found');
    $this->cancelDeal($otherKey)->assertNotFound();

    // The other partner's PUT with the same externalId is its own request, never this one.
    $this->putDeal($otherKey, $this->dealBody(['business.name' => 'Someone Else']))->assertCreated();
    expect(PartnerTenantRequest::query()->count())->toBe(2)
        ->and($this->getDeal($key)->json('businessName'))->toBe('Istanbul Market');
});

test('cancelling a pending request cancels it and tells the partner', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();

    $this->cancelDeal($key)->assertOk()->assertJsonPath('status', 'cancelled')->assertJsonPath('cancelledReason', 'Customer pulled out');
    $this->cancelDeal($key)->assertOk()->assertJsonPath('status', 'cancelled');

    expect(PartnerWebhookEvent::query()->where('type', 'tenant.cancelled')->count())->toBe(1);
});

test('cancelling an approved deal never touches the live business: it is flagged for an admin', function () {
    [, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->approveAs($this->requestFor())->assertRedirect();
    $company = Company::query()->sole();
    $before = [$company->status, $company->suspended_at];
    $licences = DB::table('licences')->orderBy('id')->get(['id', 'status'])->all();

    $this->cancelDeal($key)->assertOk()
        ->assertJsonPath('status', 'approved')
        ->assertJsonPath('partnerCancelled', true)
        ->assertJsonPath('cancelledReason', 'Customer pulled out');

    $company->refresh();
    expect([$company->status, $company->suspended_at])->toBe($before)
        ->and(DB::table('licences')->orderBy('id')->get(['id', 'status'])->all())->toEqual($licences)
        ->and($this->requestFor()->partner_cancelled_after_approval)->toBeTrue()
        ->and(PartnerWebhookEvent::query()->where('type', 'tenant.cancelled')->count())->toBe(0);
});

test('plans lists the active plans by code', function () {
    [, $key] = $this->partnerWithKey();
    $this->proPlan()->forceFill(['is_active' => false])->save();

    $plans = $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertOk()->json();

    expect(collect($plans)->firstWhere('code', 'setup-only'))->toBe([
        'code' => 'setup-only', 'name' => 'Setup only', 'setupFee' => '1200.00', 'setupFeeMode' => 'perBusiness', 'monthlyPerTill' => '0.00',
    ])->and(collect($plans)->pluck('code')->all())->not->toContain('pro');
});

test('a missing, malformed, unknown or revoked key is refused in the envelope', function () {
    [$partner, $key] = $this->partnerWithKey();

    $this->getJson('/api/partner/v1/plans')->assertUnauthorized()->assertJsonPath('code', 'auth.invalid_key')
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer nonsense'])->assertUnauthorized()->assertJsonPath('code', 'auth.invalid_key');
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer spk_'.str_repeat('a', 40)])->assertUnauthorized()->assertJsonPath('code', 'auth.invalid_key');
    // Never in a query string.
    $this->getJson('/api/partner/v1/plans?api_key='.$key)->assertUnauthorized();

    app(RevokePartnerKey::class)->handle(PartnerKey::query()->sole());
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertUnauthorized()->assertJsonPath('code', 'auth.key_revoked');

    [, $key2] = $this->partnerWithKey('Paused partner');
    app(SavePartner::class)->handle(PartnerKey::query()->where('revoked_at', null)->sole()->partner, 'Paused partner', null, false);
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key2])->assertUnauthorized()->assertJsonPath('code', 'auth.key_revoked');
});

test('repeated wrong keys are locked out per IP before any lookup', function () {
    config(['partners.failed_auth.per_ip' => 3]);

    foreach (range(1, 3) as $ignored) {
        $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer spk_'.str_repeat('b', 40)])->assertUnauthorized();
    }

    [, $key] = $this->partnerWithKey();
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertStatus(429)->assertJsonPath('code', 'rate.limited');
});

test('60 requests a minute per key, then 429 rate.limited with Retry-After; other keys and APIs are not affected', function () {
    [, $key] = $this->partnerWithKey();
    [, $otherKey] = $this->partnerWithKey('Other reseller');

    foreach (range(1, 60) as $ignored) {
        $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertOk();
    }

    $response = $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertStatus(429)
        ->assertJsonPath('code', 'rate.limited')
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey'])
        ->assertHeader('Retry-After');
    expect($response->json('retryAfterSeconds'))->toBeGreaterThan(0);

    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$otherKey])->assertOk();

    $this->travel(61)->seconds();
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertOk();
});

test('every call is audited with its outcome and never the key', function () {
    [, $key] = $this->partnerWithKey();

    $this->putDeal($key, $this->dealBody())->assertCreated();
    $this->getDeal($key, 'nope')->assertNotFound();
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer spk_'.str_repeat('c', 40)])->assertUnauthorized();

    $calls = AuditLog::query()->whereIn('action', ['partner_api.call', 'partner_api.refused'])->orderBy('created_at')->orderBy('id')->get();
    expect($calls)->toHaveCount(3)
        ->and($calls[0]->meta)->toMatchArray(['method' => 'PUT', 'endpoint' => 'api.partner.tenant-requests.upsert', 'external_id' => 'zivaro-deal-42', 'status' => 201, 'partner' => 'Zivaro'])
        ->and($calls[1]->meta)->toMatchArray(['status' => 404, 'code' => 'partner.request_not_found'])
        ->and($calls[2]->action)->toBe('partner_api.refused')
        ->and($calls[2]->meta)->toMatchArray(['status' => 401, 'code' => 'auth.invalid_key']);

    $stored = json_encode([DB::table('audit_logs')->get()->all(), DB::table('partner_keys')->get()->all(), DB::table('partners')->get()->all()]);
    expect($stored)->not->toContain($key)->and($stored)->not->toContain(substr($key, 4, 20));
});

test('validation follows the new-business form and names the field', function (array $overrides, string $field, string $message) {
    [, $key] = $this->partnerWithKey();

    $response = $this->putDeal($key, $this->dealBody($overrides))->assertStatus(422)
        ->assertJsonPath('code', 'request.invalid')
        ->assertJsonPath('rejectedKey', null)
        ->assertJsonPath('retryAfterSeconds', null)
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey', 'details' => ['fields']]);

    expect($response->json('details')['fields'][$field][0] ?? null)->toBe($message)
        ->and($response->json('message'))->toContain($message);
    expect(PartnerTenantRequest::query()->count())->toBe(0);
})->with([
    'postcode' => [['shops.0.postcode' => 'NOT A CODE'], 'shops.0.postcode', 'Enter a UK postcode like LS1 6AB.'],
    'vat number' => [['business.vatNumber' => 'FR123'], 'business.vatNumber', 'Enter a UK VAT number like GB123456789.'],
    'company number' => [['business.companyNumber' => 'ABC'], 'business.companyNumber', 'Enter a Companies House number: 8 digits, or 2 letters and 6 digits.'],
    'phone' => [['business.phone' => 'call me'], 'business.phone', 'Enter a phone number like 0113 496 0000.'],
    'owner email' => [['owner.email' => null], 'owner.email', 'The owner.email field is required.'],
    'tills' => [['shops.0.tills' => 21], 'shops.0.tills', 'Up to 20 tills per shop.'],
    'plan' => [['plan' => 'gold'], 'plan', 'Use the code of an active plan (GET /api/partner/v1/plans).'],
    'setup fee' => [['setupFeePerTill' => '7.505'], 'setupFeePerTill', 'Enter an amount in pounds with up to 2 decimal places, for example 30 or 29.99.'],
    'no shops' => [['shops' => []], 'shops', 'The shops field is required.'],
]);

test('a 9-digit VAT number and a short company number are tidied like the admin form', function () {
    [, $key] = $this->partnerWithKey();

    $this->putDeal($key, $this->dealBody(['business.vatNumber' => '123 4567 89', 'business.companyNumber' => '1234567']))->assertCreated();

    expect($this->requestFor()->payload['business'])->toMatchArray(['vatNumber' => 'GB123456789', 'companyNumber' => '01234567']);
});

test('an externalId outside 1 to 64 of [A-Za-z0-9._:-] is refused', function () {
    [, $key] = $this->partnerWithKey();

    expect($this->putDeal($key, $this->dealBody(), str_repeat('x', 65))->assertStatus(422)->json('details')['fields'])->toHaveKey('externalId');
    expect($this->putDeal($key, $this->dealBody(), 'deal 42')->assertStatus(422)->json('details')['fields'])->toHaveKey('externalId');
    $this->putDeal($key, $this->dealBody(), 'zivaro:deal_42.v1')->assertCreated();
});

test('admins and portal users cannot call the partner API with their sessions', function () {
    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->getJson('/api/partner/v1/plans')->assertUnauthorized();
});
