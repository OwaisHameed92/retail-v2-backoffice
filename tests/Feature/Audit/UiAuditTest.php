<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class)->group('audit-ui');

/*
 * UI audit 2026-10-11 (docs/audit/2026-10-11-ui.md): the server-side problems found while crawling every portal and
 * admin page on a GB and a PK instance. Each test proves one finding (UI-n) and FAILS until it is fixed.
 */

beforeEach(function () {
    $this->withoutVite();
});

/** An active owner of the business (the factory user has a verified email). */
function uiAuditOwner(Company $company): User
{
    $user = User::factory()->create();
    $company->users()->attach($user->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);

    return $user;
}

test('UI-1: browsing other throttled pages does not use up the sales export limit', function () {
    $company = $this->tenant();
    $owner = uiAuditOwner($company);

    // Ten ordinary page views a shop owner makes in a minute: "Add product" carries throttle:120,1. Every plain
    // `throttle:N,1` in routes/app.php shares ONE per-user counter (Laravel keys it by the user id only), so these
    // ten views also count against the export's throttle:10,1.
    for ($i = 0; $i < 10; $i++) {
        $this->actingAs($owner)->get('/app/products/create')->assertOk();
    }

    // Expected: the first export of the minute works. Actual: 429 Too Many Requests.
    expect($this->actingAs($owner)->get('/app/sales/export')->status())->not->toBe(429);
});

test('UI-1: polling an import page does not lock the user out of the report exports', function () {
    $company = $this->tenant();
    $owner = uiAuditOwner($company);

    // The product import page reloads every 2 s while an import runs (pages/app/products/imports/show.tsx): 30 hits
    // a minute on a throttle:120,1 route. The report CSV (throttle:30,1) then answers 429 for the rest of the minute.
    for ($i = 0; $i < 30; $i++) {
        $this->actingAs($owner)->get('/app/products/imports')->assertOk();
    }

    expect($this->actingAs($owner)->get('/app/reports/sales/export')->status())->not->toBe(429);
});

test('UI-2: the licences tab cannot move every till to another plan without the billing change', function () {
    $company = $this->licensedTenant(tills: 2);
    $pro = $this->proPlan();

    // A support admin (licences.manage, NOT billing.manage) uses "Change the plan for new tills" with
    // "also move the live licences" on the Licences tab.
    $support = $this->admin(AdminRole::Support);
    $response = $this->actingAs($support, 'admin')->put("/admin/tenants/{$company->id}/plan", ['plan_id' => $pro->id, 'apply_to_licences' => true]);

    $moved = Licence::withoutCompanyScope()->whereBelongsTo($company)->where('plan_id', $pro->id)->count();

    // Expected: moving live licences is the billing "Change plan" (preview, fee, invoices, billing.plan_changed),
    // or refused for an admin without billing.manage. Actual: both tills are on Pro, the recurring fee, setup fee
    // and Direct Debit are untouched, and no billing.plan_changed is recorded.
    expect($response->status() === 403 || ($moved === 2 && AuditLog::query()->where('action', 'billing.plan_changed')->exists()))->toBeTrue();
});

test('UI-3: Pakistan admin email settings do not describe Direct Debit emails', function () {
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);

    $this->actingAs($this->admin(AdminRole::Owner), 'admin')->get(route('admin.settings.emails'))->assertOk()
        ->assertInertia(function (AssertableInertia $page) {
            $page->component('admin/settings/emails');
            $text = json_encode($page->toArray()['props']['categories']);

            // Pakistan has no Direct Debit (manual billing, P5); the reminders category still says
            // "Direct Debit setup, failed and cancelled" (app/Domain/Mail/Enums/EmailCategory.php:36).
            expect($text)->not->toContain('Direct Debit');
        });
});

test('UI-4: Pakistan till settings refuse quick cash buttons that are not amounts', function () {
    CountryModulesFixtures::pakistan();

    // The page's help says "The amounts on the till's quick cash buttons, separated by commas" but the setting is
    // plain text (config/country.php:224, max 60): "abc,xyz" or "-5,0" is saved and pushed to every till.
    foreach (['abc,xyz', '-5,0', '100,,500'] as $bad) {
        $refused = false;
        try {
            SettingCatalogue::normalise('payments.quick_cash', $bad);
        } catch (ValidationException) {
            $refused = true;
        }

        expect($refused)->toBeTrue("quick cash '{$bad}' was accepted");
    }
});

test('UI-5: an overdue business sees a warning in the portal before its tills lock', function () {
    $company = $this->tenant();
    $owner = uiAuditOwner($company);
    $company->forceFill(['status' => CompanyStatus::Overdue])->save();

    // billingNotice (HandleInertiaRequests::billingNotice) only covers the Direct Debit set-up deadline. An overdue
    // business (suspended after 7 days, tills locked) sees nothing outside the Billing page, and the reminder emails
    // may be held by Admin → Settings → Emails (P11).
    $this->actingAs($owner)->get('/app')->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->whereNot('billingNotice', null));
});
