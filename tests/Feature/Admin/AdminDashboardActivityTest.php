<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->setVat(false);
    $this->standardPlan()->forceFill(['price_monthly' => '25.00', 'price_yearly' => '250.00'])->save();
    $this->atLondon('2026-10-24 10:00');
});

/** Module 7.1 pass 2: a tenant, a paid invoice and a lead, each at its own time. */
function seedActivity(): void
{
    test()->atLondon('2026-10-20 09:00');
    $company = test()->payingTenant('Alpha Stores', 1, 'ALP');

    test()->atLondon('2026-10-22 09:00');
    Lead::factory()->create(['business_name' => 'Corner Shop Leeds']);

    test()->atLondon('2026-10-24 10:00');
    test()->issuedFor($company, new NewInvoice(allowOverlap: true))
        ->forceFill(['status' => InvoiceStatus::Paid, 'paid_at' => CarbonImmutable::parse('2026-10-23 12:00', 'Europe/London')->utc(), 'balance' => '0.00'])
        ->saveQuietly();
}

test('recent activity lists new tenants, paid invoices and new leads, newest first, with links', function () {
    seedActivity();

    $this->actingAs($this->admin(), 'admin')->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->has('dashboard.activity', 3)
            ->where('dashboard.activity.0.kind', 'invoice')
            ->where('dashboard.activity.0.title', 'Invoice paid')
            ->where('dashboard.activity.0.detail', fn ($detail) => str_contains($detail, '£25.00') && str_contains($detail, 'Alpha Stores'))
            ->where('dashboard.activity.1.kind', 'lead')
            ->where('dashboard.activity.1.detail', 'Corner Shop Leeds')
            ->where('dashboard.activity.2.kind', 'tenant')
            ->where('dashboard.activity.2.detail', 'Alpha Stores')
            ->where('dashboard.activity.2.href', fn ($href) => str_starts_with($href, '/admin/tenants/'))
            ->missing('dashboard.activity.0.area'));
});

test('recent activity hides paid invoices without billing access and leads without leads access', function (AdminRole $role, array $kinds) {
    seedActivity();

    $this->actingAs($this->admin($role), 'admin')->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page->where('dashboard.activity', fn ($items) => collect($items)->pluck('kind')->all() === $kinds));
})->with([
    'accounts' => [AdminRole::Accounts, ['invoice', 'tenant']],
    'sales' => [AdminRole::Sales, ['lead', 'tenant']],
    'support' => [AdminRole::Support, ['tenant']],
]);

test('business overview counts tenants per status, deleted tenants left out', function () {
    $this->licensedTenant('Trial One', 1, 'TRA');
    $this->payingTenant('Paying One', 1, 'PAY')->forceFill(['status' => CompanyStatus::Active])->save();
    $this->licensedTenant('Held', 1, 'HLD')->forceFill(['status' => CompanyStatus::Suspended])->save();
    $this->licensedTenant('Gone', 1, 'GON')->delete();

    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.statuses.total', 3)
            ->where('dashboard.statuses.active', 1)
            ->where('dashboard.statuses.trial', 1)
            ->where('dashboard.statuses.suspended', 1)
            ->where('dashboard.statuses.cancelled', 0));
});
