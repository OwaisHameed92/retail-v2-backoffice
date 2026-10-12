<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Tenancy\Enums\CompanyRole;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class);

/*
 * UI audit 2026-10-11, UI-1: each `throttle:N,M` portal and admin route has its own bucket per signed-in user
 * (App\Http\Middleware\ThrottlePerRoute), so browsing or polling other throttled pages never uses up an export's
 * limit, while each route still answers 429 past its own limit.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->company = $this->tenant();
    $this->owner = $this->addMember($this->company, CompanyRole::Owner);
});

test('UI-1: browsing other throttled pages does not use up the sales export limit', function () {
    // "Add product" carries throttle:120,1; the sales export throttle:10,1.
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($this->owner)->get('/app/products/create')->assertOk();
    }

    expect($this->actingAs($this->owner)->get('/app/sales/export')->getStatusCode())->not->toBe(429);
});

test('UI-1: polling an import page does not lock the user out of the report exports', function () {
    // The import page reloads every 2 s while an import runs: 30 hits a minute; the report CSV is throttle:30,1.
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($this->owner)->get('/app/products/imports')->assertOk();
    }

    expect($this->actingAs($this->owner)->get('/app/reports/sales/export')->getStatusCode())->not->toBe(429);
});

test('a route still answers 429 past its own limit: the 11th sales export in a minute', function () {
    for ($i = 0; $i < 10; $i++) {
        expect($this->actingAs($this->owner)->get('/app/sales/export')->getStatusCode())->not->toBe(429);
    }

    $this->actingAs($this->owner)->get('/app/sales/export')->assertStatus(429);

    // Its own bucket only: other throttled pages and another user are unaffected.
    $this->actingAs($this->owner)->get('/app/products/create')->assertOk();
    $other = $this->addMember($this->company, CompanyRole::Owner);
    expect($this->actingAs($other)->get('/app/sales/export')->getStatusCode())->not->toBe(429);
});

test('admin search as the admin types does not use up the audit-log export limit', function () {
    $admin = $this->admin(AdminRole::Owner);

    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($admin, 'admin')->getJson('/admin/search?q=khan')->assertOk();
    }

    $this->actingAs($admin, 'admin')->get('/admin/audit-log/export')->assertOk();
});
