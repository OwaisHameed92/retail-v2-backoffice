<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Queries\AdminDashboard;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

// "Now" is Saturday 24 Oct 2026 10:00 London: this week started Mon 19 Oct, the 12 weeks start Mon 3 Aug.
const DASHBOARD_NOW = '2026-10-24 10:00';

beforeEach(function () {
    Mail::fake();
    $this->setVat(false);
    $this->standardPlan()->forceFill(['price_per_till_monthly' => '25.00', 'price_per_till_yearly' => '250.00'])->save();
    $this->atLondon(DASHBOARD_NOW);
});

/** A tenant created at `$london` whose tills were bound then; paid to 31 Oct unless `$paid` is false (a trial). */
function tenantActivatedAt(string $name, int $tills, string $code, string $london, bool $paid = true): Company
{
    test()->atLondon($london);
    $company = test()->licensedTenant($name, $tills, $code);

    foreach (test()->licencesOf($company) as $licence) {
        test()->activate($licence, CarbonImmutable::now(), 'PC-'.$licence->id);

        if ($paid) {
            $licence->forceFill([
                'status' => LicenceStatus::Active,
                'expires_at' => BillingDates::endOfDay(BillingDates::date('2026-10-31')),
                'grace_days' => 7,
            ])->save();
        }
    }

    test()->atLondon(DASHBOARD_NOW);

    return $company->refresh();
}

/** An issued invoice for the tenant's tills, then moved to the given state. */
function invoiceFor(Company $company, array $state): Invoice
{
    $invoice = test()->issuedFor($company, new NewInvoice(allowOverlap: true));
    $invoice->forceFill($state)->saveQuietly();

    return $invoice->refresh();
}

function londonAt(string $datetime): CarbonImmutable
{
    return CarbonImmutable::parse($datetime, 'Europe/London')->utc();
}

function dashboard(): array
{
    return app(AdminDashboard::class)->compute(CarbonImmutable::now())->forViewer(true, true);
}

test('monthly revenue is paid invoice totals this London month against last month, with a 12 week series', function () {
    $two = $this->payingTenant('Alpha Stores', 2, 'ALP');   // £50.00 invoices
    $one = $this->payingTenant('Bravo Mart', 1, 'BRV');     // £25.00 invoices

    invoiceFor($two, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-05 12:00'), 'balance' => '0.00']);
    invoiceFor($one, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-20 09:00'), 'balance' => '0.00']);
    invoiceFor($two, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-09-15 16:00'), 'balance' => '0.00']);
    invoiceFor($two, ['status' => InvoiceStatus::Void, 'voided_at' => londonAt('2026-10-06 10:00')]);
    invoiceFor($one, []); // issued, unpaid

    $revenue = dashboard()['kpis']['revenue'];

    expect($revenue['value'])->toBe('£75.00')
        ->and($revenue['footer'])->toBe('£50.00 last month')
        ->and($revenue['delta'])->toMatchArray(['value' => '50%', 'direction' => 'up'])
        ->and($revenue['series'])->toEqual([0, 0, 0, 0, 0, 0, 50, 0, 0, 50, 0, 25]);
});

test('with nothing recorded every series is zeros and money shows £0.00', function () {
    $data = dashboard();

    foreach (['revenue', 'activeTills', 'trials', 'overdue'] as $key) {
        expect($data['kpis'][$key]['series'])->toHaveCount(12)->each->toEqual(0);
    }

    expect($data['kpis']['revenue']['value'])->toBe('£0.00')
        ->and($data['kpis']['activeTills']['value'])->toBe('0')
        ->and($data['attention'])->toBe(['items' => [], 'total' => 0])
        ->and($data['recentTenants'])->toBe([]);
});

test('active tills are bound, live tills of live businesses, this week against last week', function () {
    tenantActivatedAt('Alpha Stores', 2, 'ALP', '2026-09-14 10:00');
    $revoked = tenantActivatedAt('Echo Ltd', 1, 'ECH', '2026-09-14 11:00');
    tenantActivatedAt('Bravo Mart', 1, 'BRV', '2026-10-20 10:00', paid: false);
    $this->licensedTenant('Charlie Co', 1, 'CHL'); // issued, never activated
    $suspended = tenantActivatedAt('Delta Deli', 1, 'DLT', '2026-09-14 12:00');
    $suspended->forceFill(['status' => CompanyStatus::Suspended, 'suspended_at' => londonAt('2026-09-14 12:00')])->saveQuietly();

    $this->licencesOf($revoked)[0]->forceFill(['status' => LicenceStatus::Revoked, 'revoked_at' => londonAt('2026-10-07 10:00')])->save();

    $tills = dashboard()['kpis']['activeTills'];

    expect($tills['value'])->toBe('3')
        ->and($tills['delta'])->toMatchArray(['value' => '1', 'direction' => 'up', 'label' => 'vs last week'])
        ->and($tills['footer'])->toBe('2 at the end of last week')
        ->and($tills['series'])->toBe([0, 0, 0, 0, 0, 0, 3, 3, 3, 2, 2, 3]);
});

test('trials running counts businesses on trial and how many end within 7 days', function () {
    tenantActivatedAt('Old Trial', 1, 'OLD', '2026-10-01 10:00', paid: false);   // ended 8 Oct
    tenantActivatedAt('Tango Stores', 1, 'TNG', '2026-10-18 10:00', paid: false); // ends 25 Oct
    tenantActivatedAt('Uniform News', 2, 'UNF', '2026-10-20 10:00', paid: false); // ends 27 Oct
    tenantActivatedAt('Paid Early', 1, 'PDE', '2026-10-23 10:00');                // paid during the trial

    $trials = dashboard()['kpis']['trials'];

    expect($trials['value'])->toBe('2')
        ->and($trials['delta'])->toMatchArray(['value' => '1', 'direction' => 'up'])
        ->and($trials['footer'])->toBe('2 end in the next 7 days')
        ->and($trials['series'])->toBe([0, 0, 0, 0, 0, 0, 0, 0, 1, 0, 1, 2]);
});

test('overdue is the balance of overdue invoices, with what was overdue at the end of each week', function () {
    $company = $this->payingTenant('Alpha Stores', 2, 'ALP');
    invoiceFor($company, ['status' => InvoiceStatus::Overdue, 'overdue_at' => londonAt('2026-10-20 06:00'), 'balance' => '30.00']);
    invoiceFor($company, ['status' => InvoiceStatus::Paid, 'overdue_at' => londonAt('2026-10-10 06:00'), 'paid_at' => londonAt('2026-10-15 10:00'), 'balance' => '0.00']);

    $overdue = dashboard()['kpis']['overdue'];

    expect($overdue['value'])->toBe('£30.00')
        ->and($overdue['footer'])->toBe('1 invoice overdue')
        ->and($overdue['delta'])->toBeNull()
        ->and($overdue['series'])->toEqual([0, 0, 0, 0, 0, 0, 0, 0, 0, 50, 0, 30]);
});

test('needs attention lists the newest 5 of trials ending, alerts, overdue invoices and late follow-ups', function () {
    tenantActivatedAt('Tango Stores', 1, 'TNG', '2026-10-18 12:00', paid: false); // ends 25 Oct 11:00 GMT (clocks go back) → flagged 23 Oct
    tenantActivatedAt('Later Trial', 1, 'LTR', '2026-10-21 12:00', paid: false);  // ends 28 Oct: not yet
    $company = $this->payingTenant('Alpha Stores', 1, 'ALP');
    $licence = $this->licencesOf($company)[0];

    foreach (['2026-10-24 09:00', '2026-10-20 09:00', '2026-10-10 09:00'] as $i => $seen) {
        LicenceAlert::query()->create([
            'company_id' => $company->id, 'licence_id' => $licence->id, 'type' => LicenceAlertType::DeviceMismatch,
            'fingerprint' => 'fp-'.$i, 'first_seen_at' => londonAt($seen), 'last_seen_at' => londonAt($seen), 'count' => 1,
        ]);
    }
    invoiceFor($company, ['status' => InvoiceStatus::Overdue, 'overdue_at' => londonAt('2026-10-22 06:00')]);
    invoiceFor($company, ['status' => InvoiceStatus::Overdue, 'overdue_at' => londonAt('2026-10-01 06:00')]);
    Lead::factory()->followUpAt(londonAt('2026-10-23 15:00'))->create(['business_name' => 'Late Lead']);
    Lead::factory()->followUpAt(londonAt('2026-10-26 15:00'))->create(); // not due yet

    $owner = dashboard()['attention'];

    expect($owner['total'])->toBe(7)
        ->and(array_column($owner['items'], 'label'))->toBe(['Alert', 'Lead', 'Trial', 'Invoice', 'Alert'])
        ->and($owner['items'][1])->toMatchArray(['text' => 'Follow-up overdue · Late Lead', 'tone' => 'info'])
        ->and($owner['items'][2]['text'])->toBe('Tango Stores · trial ends Sun 25 Oct, 11:00')
        ->and($owner['items'][3]['href'])->toStartWith('/admin/billing/invoices/');

    $sales = app(AdminDashboard::class)->compute(CarbonImmutable::now())->forViewer(billing: false, leads: true)['attention'];
    expect($sales['total'])->toBe(5)->and(array_column($sales['items'], 'label'))->not->toContain('Invoice');

    $support = app(AdminDashboard::class)->compute(CarbonImmutable::now())->forViewer(billing: false, leads: false)['attention'];
    expect($support['total'])->toBe(4)->and(array_column($support['items'], 'label'))->toBe(['Alert', 'Trial', 'Alert', 'Alert']);
});

test('recent tenants are the 5 most recently active, with plan, tills, MRR from paying tills and last activity', function () {
    $first = tenantActivatedAt('First Stores', 2, 'FST', '2026-09-01 10:00');
    foreach (['Second', 'Third', 'Fourth', 'Fifth', 'Sixth'] as $i => $name) {
        tenantActivatedAt("{$name} Mart", 1, strtoupper(substr($name, 0, 3)), '2026-09-0'.($i + 2).' 10:00', paid: false);
    }

    // First Stores: one more till on trial; a till validates today, so it is the most recently active.
    $licences = $this->licencesOf($first);
    $licences[0]->forceFill(['last_validated_at' => londonAt('2026-10-24 09:30')])->save();
    $licences[1]->forceFill(['status' => LicenceStatus::Trial, 'expires_at' => null])->save();

    $rows = dashboard()['recentTenants'];

    expect(array_column($rows, 'name'))->toBe(['First Stores', 'Sixth Mart', 'Fifth Mart', 'Fourth Mart', 'Third Mart'])
        ->and($rows[0])->toMatchArray(['plan' => 'Standard', 'tills' => 2, 'mrr' => '£25.00', 'status' => 'trial', 'statusLabel' => 'Trial'])
        ->and($rows[0]['lastActivityAt'])->toBe(londonAt('2026-10-24 09:30')->toIso8601String())
        ->and($rows[1]['mrr'])->toBe('£0.00');
});

test('business overview: tenants against the start of the month and 12 weeks of revenue against the 12 before', function () {
    tenantActivatedAt('Alpha Stores', 2, 'ALP', '2026-09-10 10:00');
    tenantActivatedAt('Bravo Mart', 1, 'BRV', '2026-10-02 10:00');
    $gone = tenantActivatedAt('Gone Ltd', 1, 'GNE', '2026-09-11 10:00');
    $gone->forceFill(['status' => CompanyStatus::Cancelled, 'cancelled_at' => londonAt('2026-10-05 10:00')])->saveQuietly();

    $alpha = Company::query()->where('name', 'Alpha Stores')->firstOrFail();
    invoiceFor($alpha, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-01 10:00'), 'balance' => '0.00']);
    invoiceFor($alpha, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-06-01 10:00'), 'balance' => '0.00']);
    invoiceFor($alpha, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-06-08 10:00'), 'balance' => '0.00']);

    $overview = dashboard()['overview'];

    expect($overview['tenants'])->toMatchArray(['value' => '2', 'delta' => ['value' => '0', 'direction' => 'flat', 'goodWhen' => 'up', 'label' => 'since last month']])
        ->and($overview['revenue']['value'])->toBe('£50.00')
        ->and($overview['revenue']['delta'])->toMatchArray(['value' => '50%', 'direction' => 'down']);
});

test('the revenue chart has 12 weeks, 6 or 12 months and compares with the period before', function () {
    $company = $this->payingTenant('Alpha Stores', 2, 'ALP');
    invoiceFor($company, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-05 10:00'), 'balance' => '0.00']);
    invoiceFor($company, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-03-05 10:00'), 'balance' => '0.00']);

    $owner = $this->admin();
    $this->actingAs($owner, 'admin')->get('/admin?range=6m')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/dashboard')
            ->where('range', '6m')
            ->has('revenue.points', 6)
            ->where('revenue.points.5.label', 'Oct')
            ->where('revenue.total', '£50.00')
            ->where('revenue.change.value', '0%'));

    $this->actingAs($owner, 'admin')->get('/admin?range=1y')->assertOk()
        ->assertInertia(fn ($page) => $page->has('revenue.points', 12)->where('revenue.total', '£100.00')->where('revenue.change', null));

    $this->actingAs($owner, 'admin')->get('/admin?range=nonsense')->assertOk()
        ->assertInertia(fn ($page) => $page->where('range', '12w')->has('revenue.points', 12));
});

test('money is hidden from admins without billing access and lead items without leads access', function (AdminRole $role, bool $billing, bool $leads) {
    $company = $this->payingTenant('Alpha Stores', 1, 'ALP');
    invoiceFor($company, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-05 10:00'), 'balance' => '0.00']);

    $this->actingAs($this->admin($role), 'admin')->get('/admin')->assertOk()
        ->assertInertia(fn ($page) => $page
            ->where('dashboard.access', ['billing' => $billing, 'leads' => $leads])
            ->where('dashboard.kpis.revenue.locked', ! $billing)
            ->where('dashboard.kpis.revenue.value', $billing ? '£25.00' : null)
            ->where('dashboard.kpis.overdue.locked', ! $billing)
            ->where('dashboard.kpis.activeTills.locked', false)
            ->where('dashboard.overview.revenue.value', $billing ? '£25.00' : null)
            ->where('dashboard.recentTenants.0.mrr', $billing ? '£0.00' : null)
            ->where('revenue', $billing ? fn ($chart) => $chart['total'] === '£25.00' : null));
})->with([
    'owner' => [AdminRole::Owner, true, true],
    'accounts' => [AdminRole::Accounts, true, false],
    'sales' => [AdminRole::Sales, false, true],
    'support' => [AdminRole::Support, false, false],
]);

test('the leads nav count is shared with leads staff only', function () {
    Lead::factory()->count(3)->create();
    Lead::factory()->contacted()->create();
    Lead::factory()->create()->delete(); // archived

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->get('/admin')
        ->assertInertia(fn ($page) => $page->where('admin.navCounts.leads', 3));

    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get('/admin')
        ->assertInertia(fn ($page) => $page->missing('admin.navCounts'));
});

test('the cache is shared for 60 seconds but never carries one role\'s view to another', function () {
    $company = $this->payingTenant('Alpha Stores', 1, 'ALP');
    invoiceFor($company, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-05 10:00'), 'balance' => '0.00']);

    $this->actingAs($this->admin(), 'admin')->get('/admin')
        ->assertInertia(fn ($page) => $page->where('dashboard.kpis.revenue.value', '£25.00'));

    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->get('/admin')
        ->assertInertia(fn ($page) => $page->where('dashboard.kpis.revenue.value', null)->where('dashboard.kpis.revenue.locked', true));

    invoiceFor($company, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-06 10:00'), 'balance' => '0.00']);
    $accounts = $this->admin(AdminRole::Accounts);

    $this->actingAs($accounts, 'admin')->get('/admin')
        ->assertInertia(fn ($page) => $page->where('dashboard.kpis.revenue.value', '£25.00'));

    $this->travel(61)->seconds();

    $this->actingAs($accounts, 'admin')->get('/admin')
        ->assertInertia(fn ($page) => $page->where('dashboard.kpis.revenue.value', '£50.00'));
});

test('the dashboard runs a bounded number of queries whatever the number of tenants', function () {
    $count = function (): int {
        DB::flushQueryLog();
        DB::enableQueryLog();
        app(AdminDashboard::class)->compute(CarbonImmutable::now());
        DB::disableQueryLog();

        return count(DB::getQueryLog());
    };

    $alpha = $this->payingTenant('Alpha Stores', 2, 'ALP');
    invoiceFor($alpha, ['status' => InvoiceStatus::Overdue, 'overdue_at' => londonAt('2026-10-20 06:00')]);
    $few = $count();

    foreach (['Bravo', 'Charlie', 'Delta', 'Echo', 'Foxtrot', 'Golf'] as $name) {
        $company = $this->payingTenant("{$name} Mart", 2, strtoupper(substr($name, 0, 3)));
        invoiceFor($company, ['status' => InvoiceStatus::Paid, 'paid_at' => londonAt('2026-10-05 10:00'), 'balance' => '0.00']);
        invoiceFor($company, ['status' => InvoiceStatus::Overdue, 'overdue_at' => londonAt('2026-10-20 06:00')]);
        Lead::factory()->followUpAt(londonAt('2026-10-20 10:00'))->create();
    }

    expect($count())->toBe($few)->and($few)->toBeLessThanOrEqual(20);
});

test('guests and tenant users cannot open the admin dashboard', function () {
    $this->get('/admin')->assertRedirect(route('admin.login'));

    $company = $this->tenant();
    $this->actingAs($this->ownerOf($company), 'web')->get('/admin')->assertRedirect(route('admin.login'));
});
