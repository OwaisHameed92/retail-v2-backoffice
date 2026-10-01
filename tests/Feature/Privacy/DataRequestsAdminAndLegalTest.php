<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Enums\DataRequestType;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Tenancy\Models\Company;

/*
 * Module 7.7: the admin "Data requests" list across businesses (owner and support), and the public legal pages.
 */

beforeEach(function () {
    $this->withoutVite();
    $this->khan = Company::factory()->create(['name' => 'Khan Mini Mart']);
    $this->patel = Company::factory()->create(['name' => 'Patel News']);

    foreach ([[$this->khan, DataRequestType::Export, DataRequestStatus::Completed], [$this->patel, DataRequestType::Erasure, DataRequestStatus::TillPending]] as [$company, $type, $status]) {
        (new DataRequest)->forceFill([
            'company_id' => $company->id, 'customer_id' => '01K5T0Q8C4000000000000K001', 'type' => $type, 'status' => $status,
            'till_steps' => $status === DataRequestStatus::TillPending ? [['key' => 'eReceipts', 'text' => 'x', 'count' => 1, 'shops' => []]] : null,
        ])->save();
    }
});

test('owner and support admins see every business\'s data requests; sales and accounts admins cannot; guests sign in', function (AdminRole $role, bool $allowed) {
    $response = $this->actingAs(Admin::factory()->create(['role' => $role]), 'admin')->get('/admin/data-requests');

    if (! $allowed) {
        $response->assertForbidden();

        return;
    }

    $response->assertOk()->assertInertia(fn ($page) => $page->component('admin/data-requests/index')
        ->has('requests.data', 2)
        ->where('counts.tillPending', 1)
        ->where('requests.data', fn ($rows) => collect($rows)->pluck('company')->sort()->values()->all() === ['Khan Mini Mart', 'Patel News'])
        ->where('requests.data.0.tillSteps', fn ($n) => is_int($n)));
})->with([
    'owner' => [AdminRole::Owner, true],
    'support' => [AdminRole::Support, true],
    'sales' => [AdminRole::Sales, false],
    'accounts' => [AdminRole::Accounts, false],
]);

test('the admin list filters by status and searches by business; guests are sent to the admin sign-in', function () {
    $this->get('/admin/data-requests')->assertRedirect();

    $admin = Admin::factory()->create(['role' => AdminRole::Support]);
    $this->actingAs($admin, 'admin')->get('/admin/data-requests?status=tillPending')
        ->assertInertia(fn ($page) => $page->has('requests.data', 1)->where('requests.data.0.company', 'Patel News'));
    $this->actingAs($admin, 'admin')->get('/admin/data-requests?search=Khan')
        ->assertInertia(fn ($page) => $page->has('requests.data', 1)->where('requests.data.0.type', 'export'));
});

test('the legal pages are public drafts rendered from markdown; other pages are not found', function (string $page, string $title) {
    $this->get('/legal/'.$page)->assertOk()->assertInertia(fn ($p) => $p->component('legal/show')
        ->where('title', $title)
        ->where('html', fn ($html) => str_contains($html, 'DRAFT') && ! str_contains($html, '<script'))
        ->has('pages', 4));
})->with([
    ['terms', 'Terms of service'],
    ['privacy', 'Privacy notice'],
    ['dpa', 'Data processing agreement'],
    ['subprocessors', 'Sub-processors'],
]);

test('an unknown legal page is not found', function () {
    $this->get('/legal/cookies')->assertNotFound();
    $this->get('/legal/..%2F.env')->assertNotFound();
});
