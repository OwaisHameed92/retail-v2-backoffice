<?php

use App\Domain\Catalogue\Import\ImportColumns;
use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Reporting\Reports\ReportKind;
use App\Domain\Sales\Support\SalesCsv;
use App\Domain\Shared\Country\Country;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Http\Requests\Admin\TenantRules;
use Carbon\CarbonImmutable;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Accounts\AccountsFixtures as A;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Tenants\TenantTestHelpers;
use Tests\Feature\TillData\TillFixtures;

/*
 * Pakistan plan P3: the word "VAT" shown to people comes from the country profile (GB "VAT", PK "GST"), the HMRC VAT
 * return is GB-only (feature vatReturn), and the business ids (VAT number / Companies House; NTN, STRN, SECP) follow
 * the profile. GB golden tests pin today's UK output; PK tests prove the swap.
 */

uses(TenantTestHelpers::class);

/** A Pakistan instance: COUNTRY=PK and the profile singleton rebuilt. */
function asPakistanTaxInstance(): void
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
}

beforeEach(function () {
    $this->withoutVite();
});

// GB golden tests: the UK reads exactly as before.

it('keeps the UK tax wording everywhere on GB', function () {
    $gb = app(Country::class);

    expect($gb->taxText('Sales (inc VAT)'))->toBe('Sales (inc VAT)')
        ->and(Country::tax('Choose a VAT rate.'))->toBe('Choose a VAT rate.')
        ->and($gb->vatNumberPrefix())->toBe('VAT no.')
        ->and($gb->taxIdFor('vat_number')['label'] ?? null)->toBe('VAT number')
        ->and($gb->taxIdFor('company_number')['label'] ?? null)->toBe('Companies House number')
        ->and($gb->taxIdFor('strn'))->toBeNull()
        ->and(SalesCsv::headers())->toBe(SalesCsv::HEADERS)
        ->and(SalesCsv::headers())->toContain('VAT')
        ->and(ReportKind::Vat->label())->toBe('VAT report')
        ->and(ReportKind::Vat->description())->toBe('Net, VAT and gross per rate and per period, ready for your VAT return.')
        ->and(Feature::Accounts->label())->toBe('Accounts and VAT')
        ->and(ImportColumns::fields())->toBe(ImportColumns::FIELDS)
        ->and(SettingCatalogue::find('shop.vat_number')['label'] ?? null)->toBe('VAT number on receipts')
        ->and(SettingCatalogue::find('shop.vat_number')['help'] ?? null)->toBe('Printed on receipts and VAT invoices, for example GB123456789.')
        ->and(SettingCatalogue::find('shop.company_number')['label'] ?? null)->toBe('Company number')
        ->and(SettingCatalogue::find('shop.vat_registered')['label'] ?? null)->toBe('VAT registered');
});

it('keeps VAT in the owner digest mail on GB', function () {
    expect(OwnerDigestMail::sample()->render())->toContain('Sales (inc VAT)')->not->toContain('GST');
});

it('keeps the GB tenant id rules and messages exactly as before', function () {
    $rules = TenantRules::company();

    expect($rules['vat_number'])->toBe(['nullable', 'string', 'regex:'.TenantRules::VAT_PATTERN])
        ->and($rules['company_number'])->toBe(['nullable', 'string', 'regex:'.TenantRules::COMPANY_NUMBER_PATTERN])
        ->and($rules)->not->toHaveKey('strn')
        ->and(TenantRules::branch()['vat_number'])->toBe(['nullable', 'string', 'regex:'.TenantRules::VAT_PATTERN])
        ->and(TenantRules::messages())->toMatchArray([
            'vat_number.regex' => 'Enter a UK VAT number like GB123456789.',
            'company_number.regex' => 'Enter a Companies House number: 8 digits, or 2 letters and 6 digits.',
        ])
        ->and(TenantRules::messages())->not->toHaveKey('strn.regex');
});

it('validates and tidies the GB VAT and Companies House numbers on the tenant form as before', function () {
    $this->actingAs($this->admin(), 'admin');
    $company = $this->tenant();

    $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Mini Mart', 'vat_number' => '12345', 'company_number' => 'X1'])->assertSessionHasErrors([
        'vat_number' => 'Enter a UK VAT number like GB123456789.',
        'company_number' => 'Enter a Companies House number: 8 digits, or 2 letters and 6 digits.',
    ]);
    $this->put("/admin/tenants/{$company->id}", ['name' => 'Khan Mini Mart', 'vat_number' => 'gb 123 4567 89', 'company_number' => '1234567', 'strn' => '1234567890123'])
        ->assertSessionHasNoErrors();

    $fresh = $company->fresh();
    expect($fresh?->vat_number)->toBe('GB123456789')
        ->and($fresh?->company_number)->toBe('01234567')
        ->and($fresh?->strn)->toBeNull();

    $this->get("/admin/tenants/{$company->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('tenant.vatNumber', 'GB123456789')
        ->missing('tenant.strn')
        ->where('country.taxIds.vatNumber.label', 'VAT number'));
});

it('serves the VAT return and shows its tab on GB', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Europe/London'));
    [$company] = TillFixtures::tenant();
    A::salesData($company->id);
    $owner = C::member($company, CompanyRole::Owner);

    $this->actingAs($owner)->get('/app/accounts/vat?quarter=2026-07&shop=all')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/accounts/vat')->where('country.features.vatReturn', true));
    $this->actingAs($owner)->get('/app/accounts/vat/print?quarter=2026-07&shop=all')->assertOk();
    expect($this->actingAs($owner)->get('/app/accounts/vat/csv?quarter=2026-07&shop=all')->assertOk()->streamedContent())
        ->toContain('VAT return helper (not filed)')->toContain('Amount (£)');
});

// Pakistan.

it('says GST instead of VAT on a PK instance', function () {
    asPakistanTaxInstance();
    $pk = app(Country::class);

    expect($pk->taxText('Sales (inc VAT)'))->toBe('Sales (inc GST)')
        ->and($pk->taxText('Net, VAT and gross per rate'))->toBe('Net, GST and gross per rate')
        ->and($pk->taxText('vat_rate_id stays'))->toBe('vat_rate_id stays')
        ->and(Country::tax('Choose a VAT rate.'))->toBe('Choose a GST rate.')
        ->and($pk->vatNumberPrefix())->toBe('NTN')
        ->and($pk->taxIdFor('vat_number')['label'] ?? null)->toBe('NTN')
        ->and($pk->taxIdFor('strn')['label'] ?? null)->toBe('STRN')
        ->and($pk->taxIdFor('company_number')['label'] ?? null)->toBe('SECP registration number')
        ->and(SalesCsv::headers())->toContain('GST')->not->toContain('VAT')
        ->and(ReportKind::Vat->label())->toBe('GST report')
        ->and(Feature::Accounts->label())->toBe('Accounts and GST')
        ->and(ImportColumns::fields()['vat']['label'])->toBe('GST rate')
        ->and(ImportColumns::fields()['sell_price']['help'])->toBe("In rupees, including GST. Every shop's price.")
        ->and(ImportColumns::guess(['Barcode', 'GST rate']))->toBe(['barcode' => 0, 'vat' => 1])
        ->and(SettingCatalogue::find('shop.vat_number')['label'] ?? null)->toBe('NTN on receipts')
        ->and(SettingCatalogue::find('shop.vat_number')['help'] ?? null)->toBe('Printed on receipts and GST invoices, for example 1234567-8.')
        ->and(SettingCatalogue::find('shop.company_number')['label'] ?? null)->toBe('SECP registration number')
        ->and(SettingCatalogue::find('shop.vat_registered')['label'] ?? null)->toBe('GST registered');
})->group('country-pk');

it('says GST in the owner digest mail on PK', function () {
    asPakistanTaxInstance();

    expect(OwnerDigestMail::sample()->render())->toContain('Sales (inc GST)')->not->toContain('Sales (inc VAT)');
})->group('country-pk');

it('answers 404 for the HMRC VAT return on PK and hides it in the page props', function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Europe/London'));
    [$company] = TillFixtures::tenant();
    A::salesData($company->id);
    $owner = C::member($company, CompanyRole::Owner);
    asPakistanTaxInstance();

    foreach (['/app/accounts/vat?quarter=2026-07', '/app/accounts/vat/print?quarter=2026-07', '/app/accounts/vat/csv?quarter=2026-07'] as $url) {
        $this->actingAs($owner)->get($url)->assertNotFound();
    }

    $this->actingAs($owner)->get('/app/accounts')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/accounts/chart')
        ->where('country.features.vatReturn', false)
        ->where('country.taxName', 'GST'));
})->group('country-pk');

it('labels and validates NTN, STRN and SECP numbers on the PK tenant form', function () {
    asPakistanTaxInstance();
    $this->actingAs($this->admin(), 'admin');
    $company = $this->tenant();

    expect(TenantRules::messages())->toMatchArray([
        'vat_number.regex' => 'Enter the NTN, for example 1234567-8.',
        'strn.regex' => 'Enter the STRN, for example 1234567890123.',
        'company_number.regex' => 'Enter the SECP registration number, for example 0123456.',
    ]);

    $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'vat_number' => 'GB123456789', 'strn' => '123', 'company_number' => '12'])
        ->assertSessionHasErrors([
            'vat_number' => 'Enter the NTN, for example 1234567-8.',
            'strn' => 'Enter the STRN, for example 1234567890123.',
            'company_number' => 'Enter the SECP registration number, for example 0123456.',
        ]);

    // Lenient: an NTN without its check digit, or a sole trader's CNIC.
    foreach (['1234567', '12345678', '35202-1234567-1', '3520212345671'] as $ntn) {
        $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'vat_number' => $ntn])->assertSessionHasNoErrors();
    }

    $this->put("/admin/tenants/{$company->id}", ['name' => 'Karachi Mart', 'vat_number' => '1234567-8', 'strn' => '17-00-1234-567-89', 'company_number' => '0123456'])
        ->assertSessionHasNoErrors();

    $fresh = $company->fresh();
    expect($fresh?->vat_number)->toBe('1234567-8')
        ->and($fresh?->strn)->toBe('1700123456789')
        ->and($fresh?->company_number)->toBe('0123456');

    $this->get("/admin/tenants/{$company->id}/edit")->assertInertia(fn (Assert $page) => $page
        ->where('tenant.strn', '1700123456789')
        ->where('country.taxIds.ntn.label', 'NTN')
        ->where('country.taxIds.strn.label', 'STRN')
        ->where('country.taxIds.companyNumber.label', 'SECP registration number'));
})->group('country-pk');

it('lets a PK owner keep the STRN on the business page', function () {
    asPakistanTaxInstance();
    $company = $this->tenant();
    $owner = $this->ownerOf($company);
    $form = ['name' => 'Karachi Mart', 'legal_name' => '', 'vat_number' => '1234567-8', 'strn' => '1234567890123', 'company_number' => '', 'address' => '', 'town' => '', 'postcode' => '', 'phone' => '', 'email' => '', 'receipt_footer' => ''];

    $this->actingAs($owner)->put('/app/shops/business', ['strn' => 'abc'] + $form)->assertSessionHasErrors(['strn' => 'Enter the STRN, for example 1234567890123.']);
    $this->actingAs($owner)->put('/app/shops/business', $form)->assertSessionHasNoErrors();

    expect($company->fresh()?->strn)->toBe('1234567890123');
    $this->actingAs($owner)->get('/app/shops/business')->assertInertia(fn (Assert $page) => $page->where('business.strn', '1234567890123'));
})->group('country-pk');
