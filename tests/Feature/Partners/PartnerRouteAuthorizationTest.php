<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Partners\Models\PartnerTenantRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Partners\PartnerTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Every admin partner route refuses those who may not use it: guests go to the admin sign-in, admins without the
 * ability get 403, and customers (portal users) never reach the admin area.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, PartnerTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    Http::preventStrayRequests();
    Http::fake();
    $this->standardPlan();
    $this->setupOnlyPlan();
});

/**
 * @return list<array{0: string, 1: string, 2: string}> [method, uri, ability]
 */
function partnerRoutes(): array
{
    return [
        ['get', '/admin/partner-requests', 'tenants.manage'],
        ['get', '/admin/partner-requests/{request}', 'tenants.manage'],
        ['post', '/admin/partner-requests/{request}/approve', 'tenants.manage'],
        ['post', '/admin/partner-requests/{request}/reject', 'tenants.manage'],
        ['get', '/admin/settings/partners', 'partners.manage'],
        ['post', '/admin/settings/partners', 'partners.manage'],
        ['put', '/admin/settings/partners/{partner}', 'partners.manage'],
        ['post', '/admin/settings/partners/{partner}/webhook-secret', 'partners.manage'],
        ['post', '/admin/settings/partners/{partner}/keys', 'partners.manage'],
        ['delete', '/admin/settings/partners/{partner}/keys/{key}', 'partners.manage'],
    ];
}

function partnerRouteUrl(object $test, string $uri): string
{
    [$partner, $key] = $test->partnerWithKey('Route partner '.uniqid());
    $test->putDeal($key, $test->dealBody(), 'deal-'.uniqid())->assertCreated();
    $request = PartnerTenantRequest::query()->latest('created_at')->firstOrFail();

    return strtr($uri, ['{request}' => $request->id, '{partner}' => $partner->id, '{key}' => $partner->keys()->firstOrFail()->id]);
}

test('guests are sent to the admin sign-in', function () {
    foreach (partnerRoutes() as [$method, $uri]) {
        $this->{$method}(partnerRouteUrl($this, $uri))->assertRedirect(route('admin.login'));
    }
});

test('admins without the ability get 403', function () {
    foreach (partnerRoutes() as [$method, $uri, $ability]) {
        // Accounts lacks tenants.manage; support lacks partners.manage.
        $role = $ability === 'tenants.manage' ? AdminRole::Accounts : AdminRole::Support;
        $this->actingAs($this->admin($role), 'admin')->{$method}(partnerRouteUrl($this, $uri))->assertForbidden();
    }
});

test('a portal owner signed in to their business never reaches the partner screens', function () {
    $company = $this->tenant('Khan Mini Mart');
    $owner = $this->ownerOf($company);

    foreach (partnerRoutes() as [$method, $uri]) {
        $this->actingAs($owner)->{$method}(partnerRouteUrl($this, $uri))->assertRedirect(route('admin.login'));
    }
});

test('roles with the ability get in', function () {
    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->get('/admin/partner-requests')->assertOk();
    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin/partner-requests')->assertOk();
    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->get('/admin/settings/partners')->assertOk();
});
