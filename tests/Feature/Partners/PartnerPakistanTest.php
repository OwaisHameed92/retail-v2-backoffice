<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Partners\Actions\QueuePartnerWebhook;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Enums\PartnerWebhookType;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Models\PartnerWebhookEvent;
use App\Domain\Shared\Country\Country;
use App\Domain\Tenancy\Actions\CancelCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * The partner onboarding flow is UK only (owner 2026-10-12): on a Pakistan instance (COUNTRY=PK) the partner API and
 * the admin partner pages answer 404, the nav hides them and no partner webhook is ever made or sent. The UK flow is
 * pinned by the other tests of this folder.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-12 09:00:00', 'UTC'));
    config(['country.code' => 'GB']);
    app()->forgetInstance(Country::class);
    $this->standardPlan();
    $this->setupOnlyPlan();
});

test('the partner API answers 404 in the JSON envelope on PK, even with a valid key', function () {
    [, $key] = $this->partnerWithKey();
    $this->asPakistan();

    $this->putDeal($key, $this->dealBody())->assertNotFound()
        ->assertJsonStructure(['code', 'message', 'traceId', 'retryAfterSeconds', 'rejectedKey']);
    $this->getDeal($key)->assertNotFound();
    $this->cancelDeal($key)->assertNotFound();
    $this->getJson('/api/partner/v1/plans', ['Authorization' => 'Bearer '.$key])->assertNotFound();

    expect(PartnerTenantRequest::query()->count())->toBe(0);
})->group('country-pk');

test('the admin partner pages are hidden and 404 on PK', function () {
    [$partner, $key] = $this->partnerWithKey();
    $this->putDeal($key, $this->dealBody())->assertCreated();
    $request = $this->requestFor();
    $this->asPakistan();
    $this->actingAs($this->admin(AdminRole::Owner), 'admin');

    $this->get('/admin')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('admin.partnerApi', false)
        ->missing('admin.navCounts.partnerRequests'));

    $this->get(route('admin.partner-requests.index'))->assertNotFound();
    $this->get(route('admin.partner-requests.show', $request))->assertNotFound();
    $this->post(route('admin.partner-requests.approve', $request), $this->reviewForm($request))->assertNotFound();
    $this->post(route('admin.partner-requests.reject', $request), ['reason' => 'No'])->assertNotFound();
    $this->get(route('admin.settings.partners.index'))->assertNotFound();
    $this->post(route('admin.settings.partners.store'), ['name' => 'X'])->assertNotFound();
    $this->postJson(route('admin.settings.partners.keys.store', $partner))->assertNotFound();
    $this->postJson(route('admin.settings.partners.webhook-secret', $partner))->assertNotFound();

    expect($request->refresh()->status)->toBe(PartnerRequestStatus::PendingReview);
})->group('country-pk');

test('no partner webhook is ever made or sent on PK', function () {
    $company = $this->tenant('Lahore Mart');
    [$partner] = $this->partnerWithKey();
    $request = PartnerTenantRequest::query()->create([
        'partner_id' => $partner->id, 'external_id' => 'deal-1', 'status' => PartnerRequestStatus::Approved,
        'payload' => $this->dealBody(), 'payload_hash' => str_repeat('a', 64), 'business_name' => 'Lahore Mart',
        'seller_name' => 'Ali Khan', 'seller_email' => 'ali@example.test',
    ]);
    $request->forceFill(['company_id' => $company->id])->save();
    $this->asPakistan();

    expect(app(QueuePartnerWebhook::class)->handle($request, PartnerWebhookType::Approved))->toBeNull();
    app(CancelCompany::class)->handle($company, 'Closed');
    $this->artisan('partners:send-webhooks')->expectsOutputToContain('Queued 0 webhooks.')->assertSuccessful();

    expect(PartnerWebhookEvent::query()->count())->toBe(0)
        ->and($request->refresh()->status)->toBe(PartnerRequestStatus::Approved);
    Http::assertNothingSent();
})->group('country-pk');

test('the shared country flags are unchanged: partnerApi is server-side only', function () {
    expect(app(Country::class)->feature('partnerApi'))->toBeTrue()
        ->and(app(Country::class)->toFrontend()['features'])->not->toHaveKey('partnerApi');

    $this->asPakistan();
    expect(app(Country::class)->feature('partnerApi'))->toBeFalse()
        ->and(app(Country::class)->toFrontend()['features'])->not->toHaveKey('partnerApi');
})->group('country-pk');
