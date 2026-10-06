<?php

use App\Domain\Accounts\Export\ExportTarget;
use App\Domain\Billing\Data\InvoiceDocument;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\CustomerStatementMail;
use App\Domain\Mail\Mailables\DirectDebitFailedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\LocalText;
use App\Domain\ShopSettings\Support\SettingCatalogue;
use App\Http\Requests\Api\StoreTrialRequest;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

/*
 * Pakistan plan P6: emails, PDFs and legal text without UK-only wording off GB. GB golden tests pin today's UK output
 * (mail lines, our own invoice's seller block, support contact, legal pages); PK tests prove the swap.
 */

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/** A Pakistan instance: COUNTRY=PK and the profile singleton rebuilt. */
function asPakistanContentInstance(): void
{
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
}

/** Our own seller details, as an instance's .env sets them (BILLING_SELLER_*, BILLING_VAT_NUMBER). */
function sellerConfig(string $legalName, string $companyNumber, string $vatNumber, string $email): void
{
    config([
        'billing.seller.legal_name' => $legalName,
        'billing.seller.address' => 'Unit 4, Example Park',
        'billing.seller.company_number' => $companyNumber,
        'billing.seller.email' => $email,
        'billing.seller.phone' => '',
        'billing.vat.number' => $vatNumber,
    ]);
}

/** A mail's text with the style block and tags removed. */
function mailText(object $mail): string
{
    return strip_tags((string) preg_replace('#<style.*?</style>#s', '', $mail->render()));
}

function invoiceHtml(object $test): string
{
    $invoice = $test->issuedFor($test->payingTenant(tills: 2));

    return view('billing.invoice-pdf', ['doc' => InvoiceDocument::for($invoice), 'logo' => ''])->render();
}

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/London'));
    config(['sspos.support_email' => 'support@switchandsave.co.uk', 'sspos.support_phone' => '0113 496 0000']);
});

// GB golden tests: the UK reads exactly as before.

it('keeps the welcome, invoice and payment failed emails on GB', function () {
    $welcome = WelcomeTenantMail::sample();
    $invoice = InvoiceMail::sample();
    $failed = DirectDebitFailedMail::sample();

    expect($welcome->subjectLine())->toBe('Welcome to Switch & Save – your licence keys')
        ->and(mailText($welcome))->toContain('Sign in with aisha@khanminimart.co.uk.')
        ->toContain('download and install SSPOS from switchandsave.co.uk/download.')
        ->toContain('Need help? Email support@switchandsave.co.uk or call 0113 496 0000.')
        ->toContain('© 2026 Switch &amp; Save. All rights reserved.')
        ->and($invoice->subjectLine())->toBe('Invoice INV-000042 from Switch & Save, due 31 October 2026')
        ->and(mailText($invoice))->toContain('The amount due is £90.00, by 31 October 2026.')
        ->toContain("Switch &amp; Save Ltd\nSort code 12-34-56\nAccount 12345678")
        ->toContain('Need help? Email support@switchandsave.co.uk or call 0113 496 0000.')
        ->and($failed->subjectLine())->toBe('Your Direct Debit payment failed')
        ->and(mailText($failed))->toContain('we could not collect £60.00 by Direct Debit for Khan Mini Mart.')
        ->toContain('Need help? Email support@switchandsave.co.uk or call 0113 496 0000.');
});

it('keeps the UK sample contact details in the mail previews on GB', function () {
    expect(AdminNewLeadMail::sample()->data->email)->toBe('imran@patelnews.co.uk')
        ->and(AdminNewLeadMail::sample()->data->phone)->toBe('07700 900123')
        ->and(SetPasswordMail::sample()->data->url)->toEndWith('/reset-password/sample-token?email=aisha%40khanminimart.co.uk')
        ->and(CustomerStatementMail::sample()->data->businessPhone)->toBe('0113 496 0123');
});

it('keeps the seller block of our own invoice on GB', function () {
    sellerConfig('Switch & Save Ltd', '01234567', 'GB123456789', 'accounts@switchandsave.co.uk');

    expect(invoiceHtml($this))
        ->toContain('Switch &amp; Save Ltd · Registered in England and Wales, company no. 01234567')
        ->toContain('· VAT no. GB123456789')
        ->toContain('<div class="muted">VAT no. GB123456789</div>')
        ->toContain('<div class="muted">accounts@switchandsave.co.uk</div>')
        ->toContain('£')
        ->and(LocalText::registration('01234567'))->toBe('Registered in England and Wales, company no. 01234567');
});

it('lets BILLING_SELLER_REGISTERED_IN name another UK jurisdiction', function () {
    config(['billing.seller.registered_in' => 'Scotland']);

    expect(LocalText::registration('SC123456'))->toBe('Registered in Scotland, company no. SC123456');
});

it('keeps the UK legal pages byte for byte on GB', function (string $page, string $ukText) {
    $expected = (string) Str::markdown((string) file_get_contents(resource_path("legal/{$page}.md")), ['html_input' => 'escape', 'allow_unsafe_links' => false]);

    $this->get('/legal/'.$page)->assertOk()->assertInertia(fn ($p) => $p->component('legal/show')
        ->where('page', $page)
        ->where('html', $expected)
        ->where('html', fn ($html) => str_contains($html, $ukText))
        ->has('pages', 4));
})->with([
    ['privacy', "complain to the Information Commissioner's Office (ico.org.uk)"],
    ['terms', 'Governing law: England and Wales.'],
    ['dpa', '(UK GDPR'],
    ['subprocessors', 'GoCardless'],
]);

it('keeps the UK messages and help text on GB', function () {
    $this->postJson('/api/v1/public/trial-requests', [], ['Origin' => 'https://elsewhere.example'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This website is not allowed to send trial requests yet. Please use the form at switchandsave.co.uk.');

    expect((new StoreTrialRequest)->messages()['email.email'])->toBe('Enter a valid email address, like name@yourshop.co.uk.')
        ->and(SettingCatalogue::find('shop.website')['help'] ?? null)->toBe('For example www.yourshop.co.uk.')
        ->and(ExportTarget::QuickBooks->importHelp())->toBe('In QuickBooks Online: Settings → Import data → Journal entries (UK dates, dd/mm/yyyy).')
        ->and(LocalText::region())->toBe('UK')
        ->and(LocalText::domains('you@yourshop.co.uk'))->toBe('you@yourshop.co.uk')
        ->and(LocalText::phone('07700 900123'))->toBe('07700 900123');
});

// PK: no UK law, regulator or domain wording; the seller block and contacts from the instance's settings.

it('writes the emails without UK wording on a Pakistan instance', function () {
    asPakistanContentInstance();
    // What the PK instance's .env sets (SSPOS_SUPPORT_EMAIL, SSPOS_SUPPORT_PHONE, SSPOS_EPOS_DOWNLOAD_URL).
    config(['sspos.support_email' => 'support@switchandsave.pk', 'sspos.support_phone' => '042 35761234', 'sspos.epos_download_url' => 'https://switchandsave.pk/download']);

    $welcome = mailText(WelcomeTenantMail::sample());
    $invoice = mailText(InvoiceMail::sample());
    $lead = AdminNewLeadMail::sample();

    expect($welcome)->toContain('Sign in with aisha@khanminimart.pk.')
        ->toContain('Need help? Email support@switchandsave.pk or call 042 35761234.')
        ->and($invoice)->toContain('The amount due is Rs 90, by 31 October 2026.')
        ->and($lead->data->email)->toBe('imran@patelnews.pk')
        ->and($lead->data->phone)->toBe('0300 1234567')
        ->and(CustomerStatementMail::sample()->data->businessPhone)->toBe('0300 1234567')
        ->and(SetPasswordMail::sample()->data->url)->toEndWith('email=aisha%40khanminimart.pk');

    foreach ([$welcome, $invoice, mailText($lead), mailText(SetPasswordMail::sample())] as $text) {
        expect($text)->not->toContain('.co.uk')->not->toContain('HMRC')->not->toContain('GDPR')
            ->not->toContain('ICO')->not->toContain('England')->not->toContain('UK ')->not->toContain('£')
            ->not->toContain('07700')->not->toContain('0113');
    }
})->group('country-pk');

it('prints our own seller block from the instance settings on a Pakistan invoice', function () {
    asPakistanContentInstance();
    sellerConfig('Switch & Save (Pvt) Ltd', '0123456', '1234567-8', 'accounts@switchandsave.pk');

    $html = invoiceHtml($this);

    expect($html)->toContain('Switch &amp; Save (Pvt) Ltd · Registered in Pakistan, SECP registration number 0123456')
        ->toContain('· NTN 1234567-8')
        ->toContain('<div class="muted">accounts@switchandsave.pk</div>')
        ->toContain('Rs ')
        ->not->toContain('England')->not->toContain('VAT no.')->not->toContain('£')->not->toContain('HMRC');

    config(['billing.seller.registered_in' => 'Islamabad Capital Territory']);
    expect(LocalText::registration('0123456'))->toBe('Registered in Islamabad Capital Territory, SECP registration number 0123456');
})->group('country-pk');

it('serves the Pakistan legal pages without UK law or regulator names', function (string $page, ?string $pkText) {
    asPakistanContentInstance();

    $this->get('/legal/'.$page)->assertOk()->assertInertia(fn ($p) => $p->component('legal/show')
        ->where('html', fn ($html) => str_contains($html, 'DRAFT')
            && ($pkText === null || str_contains($html, $pkText))
            && ! str_contains($html, 'UK GDPR') && ! str_contains($html, 'Information Commissioner')
            && ! str_contains($html, 'ico.org.uk') && ! str_contains($html, 'England') && ! str_contains($html, 'UK retail'))
        ->has('pages', 4));
})->with([
    ['privacy', 'the laws of Pakistan, including the Prevention of Electronic Crimes Act 2016'],
    ['terms', 'Governing law: Pakistan.'],
    ['dpa', 'placeholder for the data processing agreement.'],
    ['subprocessors', null],
])->group('country-pk');

it('localises the example addresses, dates and website in messages on a Pakistan instance', function () {
    asPakistanContentInstance();
    config(['sspos.website_url' => 'https://www.switchandsave.pk']);

    $this->postJson('/api/v1/public/trial-requests', [], ['Origin' => 'https://elsewhere.example'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This website is not allowed to send trial requests yet. Please use the form at switchandsave.pk.');

    expect((new StoreTrialRequest)->messages()['email.email'])->toBe('Enter a valid email address, like name@yourshop.pk.')
        ->and(SettingCatalogue::find('shop.website')['help'] ?? null)->toBe('For example www.yourshop.pk.')
        ->and(ExportTarget::QuickBooks->importHelp())->toBe('In QuickBooks Online: Settings → Import data → Journal entries (dates dd/mm/yyyy).')
        ->and(LocalText::region())->toBe('Pakistan')
        ->and(app(Country::class)->registeredIn())->toBe('Pakistan');
})->group('country-pk');
