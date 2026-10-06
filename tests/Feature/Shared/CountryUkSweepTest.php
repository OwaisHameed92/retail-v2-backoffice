<?php

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ToolWindow;
use App\Domain\Ai\Support\PromptCountry;
use App\Domain\Ai\Tools\GetCompanyOverview;
use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Mail\Mailables\AdminTillRequestMail;
use App\Domain\Mail\Mailables\AnomalyAlertMail;
use App\Domain\Mail\Mailables\OwnerAlertMail;
use App\Domain\Mail\Mailables\PortalInvitationMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Mail\Support\MorningSummaryMail;
use App\Domain\PortalUsers\Support\RoleMatrix;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\LocalText;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Nation;
use App\Domain\Tenancy\Models\Branch;
use App\Http\Requests\Admin\StoreBranchRequest;
use App\Http\Requests\Admin\StoreTenantRequest;
use App\Http\Requests\App\Pricing\ShopPriceRequest;
use App\Http\Requests\App\Setup\StaffRequest;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Pakistan plan P9: the UK-only things a Pakistan user could still see (docs/pakistan-uk-sweep.md). GB golden tests pin
 * the UK text and props that now go through the country profile; PK tests (`country-pk`) prove the swap, and one test
 * reads the main portal and admin pages of a Pakistan instance and fails on any UK-only term in their props.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/**
 * A Pakistan instance: COUNTRY=PK, the profile singleton rebuilt, the zone-derived config loaded again and the till
 * settings catalogue read afresh (it is cached per process and written with the profile's symbol).
 */
function asPakistanSweepInstance(): void
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
    config(['reporting' => require config_path('reporting.php'), 'till-health' => require config_path('till-health.php')]);
    Closure::bind(fn () => SettingCatalogue::$sections = null, null, SettingCatalogue::class)();
}

/** UK-only words a Pakistan page must not carry (case-sensitive; field names are not scanned, only values). */
const PK_UK_DENY = '/England|Scotland|Wales|Northern Ireland|\bNation\b|\bUK\b|United Kingdom|British|\bHMRC\b|Making Tax Digital|'
    .'Companies House|GDPR|\bICO\b|\bNHS\b|Challenge 25|Trading Standards|Food Standards|\bpounds\b|\bpence\b|£|\bGBP\b|'
    .'en-GB|Europe\/London|Postcode|postcode|Leeds|Bradford|\bLDS\b|07700|0113|\+44|\.co\.uk|Direct Debit|GoCardless|'
    .'[Bb]ank holiday|National (Minimum|Living) Wage|\b\d+p\b|\bVAT\b/';

/**
 * Every string value in a page's props (not the keys), without the shared `country` profile and route list.
 *
 * @param  array<mixed>  $props
 * @return list<string>
 */
function sweepStrings(array $props): array
{
    unset($props['country'], $props['ziggy'], $props['errors']);
    $out = [];
    array_walk_recursive($props, function ($value) use (&$out) {
        if (is_string($value)) {
            $out[] = $value;
        }
    });

    return $out;
}

/**
 * The UK terms found in these texts, with where they were found.
 *
 * @param  list<string>  $texts
 * @return list<string>
 */
function ukTermsIn(string $where, array $texts): array
{
    $hits = [];
    foreach ($texts as $text) {
        if (preg_match_all(PK_UK_DENY, $text, $m)) {
            $hits[] = $where.': '.implode(', ', array_unique($m[0])).' in "'.mb_substr($text, 0, 160).'"';
        }
    }

    return $hits;
}

/** A Pakistani business with its shop renamed off the helpers' UK sample ("Leeds"). */
function pkSweepTenant(object $test): array
{
    $company = $test->tenant('Lahore Mart', 2, 'LHR');
    $branch = $test->branchOf($company, 'LHR');
    $branch->forceFill(['name' => 'Gulberg', 'address' => '12 Main Boulevard', 'town' => 'Lahore', 'postcode' => '54000'])->saveQuietly();

    return [$company->refresh(), $branch->refresh()];
}

function overviewJson(object $test, $company): string
{
    app(CurrentCompany::class)->set($company);

    return (string) json_encode(app(GetCompanyOverview::class)->handle([], AiContext::forUser($test->ownerOf($company), $company)));
}

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
});

// GB golden: the UK text and props, exactly as before P9.

it('keeps the four UK nations, their labels and the nation on GB pages and in the AI overview', function () {
    expect(Nation::options())->toBe([
        ['value' => 'england', 'label' => 'England'],
        ['value' => 'scotland', 'label' => 'Scotland'],
        ['value' => 'wales', 'label' => 'Wales'],
        ['value' => 'northernIreland', 'label' => 'Northern Ireland'],
    ])->and(Nation::shown())->toBeTrue()
        ->and(app(Country::class)->nations())->toBe(['england', 'scotland', 'wales', 'northernIreland'])
        ->and(app(Country::class)->samplePlaces())->toBe([])
        ->and(app(Country::class)->toFrontend())->not->toHaveKey('samplePlaces');

    $company = $this->tenant();
    $branch = $this->branchOf($company);
    $branch->forceFill(['nation' => Nation::Wales])->saveQuietly();

    $this->actingAs($this->admin(), 'admin')->get('/admin/tenants/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nations', Nation::options()));
    $this->actingAs($this->admin(), 'admin')->get("/admin/tenants/{$company->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('branches.0.nation', 'wales')->where('branches.0.nationLabel', 'Wales')->where('nations', Nation::options()));
    $this->actingAs($this->ownerOf($company), 'web')->get("/app/shops/{$branch->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('shop.nation', 'Wales'));

    expect(overviewJson($this, $company))->toContain('"nation":"wales"');
});

it('keeps the UK wording of messages, settings, permissions, prompts and AI tools on GB', function () {
    expect((new StaffRequest)->messages()['rate_per_hour.regex'])->toBe('Enter an hourly rate in pounds, e.g. 11.44.')
        ->and((new ShopPriceRequest)->messages()['price.regex'])->toBe('Enter the price in pounds, e.g. 1.39.')
        ->and((new StoreBranchRequest)->messages()['name.required'])->toBe('Enter the branch name, for example Leeds.')
        ->and((new StoreTenantRequest)->messages()['branch_name.required'])->toBe('Enter the branch name, for example Leeds.')
        ->and(LocalText::places('1 more till for Leeds (LDS)'))->toBe('1 more till for Leeds (LDS)')
        ->and(LocalText::currency('Enter an amount in pounds, like 1.25.'))->toBe('Enter an amount in pounds, like 1.25.')
        ->and(LocalText::ukOnly('Challenge 25: usually 25.', 'Usually 25.'))->toBe('Challenge 25: usually 25.');

    $settings = SettingCatalogue::all();
    expect([$settings['till.keypad_price_in_pence']['label'], $settings['till.keypad_price_in_pence']['help']])
        ->toBe(['Type prices in pence', 'On: typing 150 on the keypad means £1.50. Off: type 1.50.'])
        ->and([$settings['payments.round_cash_to_5p']['label'], $settings['payments.round_cash_to_5p']['help']])->toBe(['Round cash to 5p', 'Cash totals are rounded to the nearest 5p.'])
        ->and($settings['compliance.challenge25_age']['help'])->toBe('Challenge 25: usually 25.')
        ->and($settings['compliance.refusal_register_enabled']['help'])->toBe('Staff record each refused sale, as Trading Standards expect.');

    $billing = collect(RoleMatrix::rows())->firstWhere('key', 'billing.manage');
    expect($billing['label'])->toBe('Set up the Direct Debit')
        ->and(ToolWindow::properties()['period']['description'])->toBe('Trading days to read (UK dates). "custom" uses from and to. Default last7Days.')
        ->and(PromptCountry::localise('You help a UK convenience-store owner check a list.'))->toBe('You help a UK convenience-store owner check a list.');
});

it('keeps the UK samples of the admin email previews on GB', function () {
    expect(AdminTillRequestMail::sample()->data->what)->toBe('1 more till for Leeds (LDS)')
        ->and(AdminTillRequestMail::sample()->data->message)->toBe('We are putting a second counter in for the lottery. Can we have it by Friday?')
        ->and(OwnerAlertMail::sample()->data->shopName)->toBe('Leeds')
        ->and(AnomalyAlertMail::sample()->data->title)->toBe('Leeds: no sales since 12:00 today')
        ->and(PortalInvitationMail::sample()->data->branchName)->toBe('Leeds Road')
        ->and(WelcomeTenantMail::sample()->data->directDebitDays)->toBe(3)
        ->and(MorningSummaryMail::sample('https://p', 'https://s')['narrative'])->toStartWith('Yesterday your 2 shops took £3,120.40, up 8.0% on the same day last week. Leeds led the way');
});

it('keeps lang="en-GB" on the GB invoice PDF and uses the profile locale on PK', function () {
    $html = fn () => view('billing.invoice-pdf', ['doc' => InvoiceDocument::for($this->issuedFor($this->payingTenant(tills: 1))), 'logo' => ''])->render();

    expect($html())->toContain('<html lang="en-GB">');

    asPakistanSweepInstance();
    config(['billing.vat.enabled' => false]);
    expect($html())->toContain('<html lang="en-PK">');
});

// Pakistan.

it('hides the UK nation on a Pakistan instance and keeps the till value as stored', function () {
    asPakistanSweepInstance();
    [$company, $branch] = pkSweepTenant($this);

    expect(Nation::options())->toBe([])->and(Nation::shown())->toBeFalse()
        ->and(app(Country::class)->toFrontend()['samplePlaces'])->toMatchArray(['Leeds' => 'Lahore', 'LDS' => 'LHR']);

    $this->actingAs($this->admin(), 'admin')->get('/admin/tenants/create')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('nations', []));
    $this->actingAs($this->admin(), 'admin')->get("/admin/tenants/{$company->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('branches.0.nationLabel', null));
    $this->actingAs($this->ownerOf($company), 'web')->get("/app/shops/{$branch->id}")->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('shop.nation', null));
    expect(overviewJson($this, $company))->not->toContain('"nation"');

    // The hidden field still sends the column default, so the till gets the value it always had.
    $this->actingAs($this->admin(), 'admin')
        ->post("/admin/tenants/{$company->id}/branches", ['code' => 'KHI', 'name' => 'Saddar', 'nation' => 'england', 'tills' => 1, 'town' => 'Karachi'])
        ->assertSessionHas('success');
    expect(Branch::withoutCompanyScope()->where('code', 'KHI')->firstOrFail()->nation)->toBe(Nation::England);
})->group('country-pk');

it('words messages, settings, permissions, prompts and AI tools without UK terms on a Pakistan instance', function () {
    asPakistanSweepInstance();

    expect((new StaffRequest)->messages()['rate_per_hour.regex'])->toBe('Enter an hourly rate in rupees, e.g. 11.44.')
        ->and((new ShopPriceRequest)->messages()['price.regex'])->toBe('Enter the price in rupees, e.g. 1.39.')
        ->and((new StoreBranchRequest)->messages()['name.required'])->toBe('Enter the branch name, for example Lahore.')
        ->and((new StoreTenantRequest)->messages()['branch_name.required'])->toBe('Enter the branch name, for example Lahore.')
        ->and(LocalText::places('1 more till for Leeds (LDS) on Leeds Road'))->toBe('1 more till for Lahore (LHR) on Mall Road');

    $settings = SettingCatalogue::all();
    expect([$settings['till.keypad_price_in_pence']['label'], $settings['till.keypad_price_in_pence']['help']])
        ->toBe(['Type prices without the decimal point', 'On: typing 150 on the keypad means Rs 1.50. Off: type 1.50.'])
        ->and([$settings['payments.round_cash_to_5p']['label'], $settings['payments.round_cash_to_5p']['help']])->toBe(['Round cash totals', 'Cash totals are rounded to the nearest 0.05.'])
        ->and($settings['compliance.challenge25_age']['help'])->toBe('Usually 25.')
        ->and($settings['compliance.refusal_register_enabled']['help'])->toBe('Staff record each refused sale.');

    $billing = collect(RoleMatrix::rows())->firstWhere('key', 'billing.manage');
    expect($billing['label'])->toBe('Manage the subscription')
        ->and(ToolWindow::properties()['period']['description'])->toBe('Trading days to read (Pakistan dates). "custom" uses from and to. Default last7Days.')
        ->and(PromptCountry::localise('You help a UK convenience-store owner check a list.'))->toBe('You help a convenience-store owner in Pakistan check a list.');
})->group('country-pk');

it('fills the admin email previews with Pakistani samples', function () {
    asPakistanSweepInstance();

    expect(AdminTillRequestMail::sample()->data->what)->toBe('1 more till for Lahore (LHR)')
        ->and(AdminTillRequestMail::sample()->data->message)->not->toContain('lottery')
        ->and(OwnerAlertMail::sample()->data->shopName)->toBe('Lahore')
        ->and(PortalInvitationMail::sample()->data->branchName)->toBe('Mall Road')
        ->and(WelcomeTenantMail::sample()->data->directDebitDays)->toBeNull()
        ->and(WelcomeTenantMail::sample()->data->billingUrl)->toBeNull();

    // Our own contact details come from the instance's .env (SSPOS_*): the PK server sets its own.
    config([
        'sspos.support_email' => 'support@switchandsave.pk', 'sspos.staff_email' => 'staff@switchandsave.pk',
        'sspos.website_url' => 'https://switchandsave.pk', 'sspos.epos_download_url' => 'https://switchandsave.pk/download',
    ]);
    $admin = $this->admin();
    $hits = [];
    foreach (EmailTemplates::keys() as $template) {
        $response = $this->actingAs($admin, 'admin')->get("/admin/emails/templates/{$template}/preview");
        if ($response->status() === 404) {
            continue; // Templates not listed on this instance (the Direct Debit ones on PK).
        }
        $text = html_entity_decode(strip_tags((string) preg_replace('#<style.*?</style>#s', '', (string) $response->assertOk()->getContent())));
        $hits = [...$hits, ...ukTermsIn("email {$template}", [$text])];
    }

    expect($hits)->toBe([]);
})->group('country-pk');

it('carries no UK-only terms in the props of the main Pakistan portal and admin pages', function () {
    asPakistanSweepInstance();
    [$company, $branch] = pkSweepTenant($this);
    $owner = $this->ownerOf($company);
    $admin = $this->admin();

    $portal = [
        '/app', '/app/shops', "/app/shops/{$branch->id}", '/app/shops/business', '/app/settings', '/app/settings/notifications',
        '/app/users', '/app/staff', '/app/staff/create', '/app/staff/time/timesheets', '/app/products', '/app/products/create',
        '/app/products/imports', '/app/prices', '/app/labels', '/app/promotions', '/app/promotions/create', '/app/customers',
        '/app/customers/create', '/app/suppliers', '/app/suppliers/create', '/app/purchasing/orders', '/app/stock',
        '/app/compliance', '/app/compliance/recalls', '/app/compliance/age-checks', '/app/calendar', '/app/calendar/special-days',
        '/app/cash', '/app/reports', '/app/reports/vat', '/app/reports/sales', '/app/accounts', '/app/accounts/export',
        '/app/billing', '/app/privacy', '/app/payment-types', '/app/reasons',
    ];
    $hits = [];
    foreach ($portal as $url) {
        $response = $this->actingAs($owner, 'web')->get($url);
        expect($response->status())->toBe(200, $url);
        $hits = [...$hits, ...ukTermsIn($url, sweepStrings($response->inertiaProps()))];
    }

    foreach (['/admin/tenants/create', "/admin/tenants/{$company->id}", '/admin/leads/create', '/admin/plans/create', '/admin/emails/templates', '/admin/billing'] as $url) {
        $response = $this->actingAs($admin, 'admin')->get($url);
        expect($response->status())->toBe(200, $url);
        $hits = [...$hits, ...ukTermsIn($url, sweepStrings($response->inertiaProps()))];
    }

    expect($hits)->toBe([]);
})->group('country-pk');
