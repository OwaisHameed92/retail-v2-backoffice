<?php

use App\Domain\Mail\Data\AccountSuspendedData;
use App\Domain\Mail\Data\LicenceRenewedData;
use App\Domain\Mail\Data\NewLeadData;
use App\Domain\Mail\Data\RenewedTillData;
use App\Domain\Mail\Data\SetPasswordData;
use App\Domain\Mail\Data\TillKeyData;
use App\Domain\Mail\Data\TrialReminderData;
use App\Domain\Mail\Data\WelcomeTenantData;
use App\Domain\Mail\Mailables\AccountReactivatedMail;
use App\Domain\Mail\Mailables\AccountSuspendedMail;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\BrandedMailable;
use App\Domain\Mail\Mailables\LicenceRenewedMail;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\TrialEndedMail;
use App\Domain\Mail\Mailables\TrialReminderMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Support\EmailTemplates;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Carbon;

beforeEach(function () {
    config([
        'app.url' => 'https://portal.example.test',
        'sspos.portal_url' => 'https://portal.example.test',
        'sspos.support_email' => 'help@switchandsave.test',
        'sspos.support_phone' => '0113 496 0000',
        'sspos.staff_email' => 'team@switchandsave.test',
        'sspos.epos_download_url' => 'https://download.example.test/sspos',
    ]);
});

it('registers every branded template once, queued and encrypted', function () {
    // Modules add templates (1.6 leads, 1.8 invoice): every one is listed once with a unique key.
    expect(count(EmailTemplates::MAILABLES))->toBeGreaterThanOrEqual(10)
        ->and(array_unique(EmailTemplates::MAILABLES))->toHaveCount(count(EmailTemplates::MAILABLES))
        ->and(array_unique(EmailTemplates::keys()))->toHaveCount(count(EmailTemplates::MAILABLES));

    foreach (EmailTemplates::MAILABLES as $class) {
        expect(is_subclass_of($class, BrandedMailable::class))->toBeTrue()
            ->and(is_subclass_of($class, ShouldQueue::class))->toBeTrue()
            ->and(is_subclass_of($class, ShouldBeEncrypted::class))->toBeTrue();
    }
});

it('renders every template with the Switch & Save theme, logo and footer', function (string $key) {
    $mailable = EmailTemplates::sample($key);

    $html = (string) $mailable->render();

    expect($html)
        ->toContain('https://portal.example.test/images/brand/switch-save-logo.png')
        ->toContain('Smart Solutions for Smart Businesses')
        ->toContain('help@switchandsave.test')
        ->toContain('0113 496 0000')
        ->toContain('#015cfc')          // brand blue, inlined from the switch-save theme
        ->not->toContain('<pre>')       // no Markdown indentation accidents
        ->not->toContain('<code>')
        ->not->toContain('Laravel');

    expect($mailable->renderText())->toContain('Smart Solutions for Smart Businesses')->not->toContain('<table');
})->with(fn () => EmailTemplates::keys());

it('uses plain sentence-case subjects', function () {
    expect(WelcomeTenantMail::sample()->subjectLine())->toBe('Welcome to Switch & Save – your licence keys')
        ->and(SetPasswordMail::sample()->subjectLine())->toBe('Set your Switch & Save password')
        ->and(TrialEndedMail::sample()->subjectLine())->toBe('Your Switch & Save free trial has ended')
        ->and(AccountSuspendedMail::sample()->subjectLine())->toBe('Your Switch & Save account is suspended')
        ->and(AccountReactivatedMail::sample()->subjectLine())->toBe('Your Switch & Save account is active again')
        ->and(AdminNewLeadMail::sample()->subjectLine())->toBe('New trial request: Patel News & Booze');

    foreach (EmailTemplates::keys() as $key) {
        expect(EmailTemplates::sample($key)->subjectLine())->not->toContain('!');
    }
});

it('words the trial reminder subject by days left', function (int $days, string $subject) {
    $mail = new TrialReminderMail(new TrialReminderData('Khan Mini Mart', 'Aisha Khan', $days, now()->addDays($days), 2));

    expect($mail->subjectLine())->toBe($subject);
})->with([
    [0, 'Your free trial ends today'],
    [1, 'Your free trial ends tomorrow'],
    [2, 'Your free trial ends in 2 days'],
]);

it('shows every till and licence key in the welcome email with the activation steps', function () {
    $mail = new WelcomeTenantMail(new WelcomeTenantData(
        businessName: 'Khan Mini Mart',
        ownerName: 'Aisha Khan',
        ownerEmail: 'aisha@example.test',
        loginUrl: 'https://portal.example.test/login',
        tills: [
            new TillKeyData('High Street', 'Front till', 'SSP-AAAA-BBBB-CCCC-1111'),
            new TillKeyData('Station Road', 'Back till', 'SSP-DDDD-EEEE-FFFF-2222'),
        ],
        trialDays: 7,
    ));

    $mail->assertSeeInHtml('Welcome to Switch &amp; Save, Aisha', false)
        ->assertSeeInHtml('Khan Mini Mart')
        ->assertSeeInHtml('High Street')
        ->assertSeeInHtml('Front till')
        ->assertSeeInHtml('SSP-AAAA-BBBB-CCCC-1111')
        ->assertSeeInHtml('SSP-DDDD-EEEE-FFFF-2222')
        ->assertSeeInHtml('This is the only time we send them')
        ->assertSeeInHtml('https://download.example.test/sspos')
        ->assertSeeInHtml('https://portal.example.test/login')
        ->assertSeeInHtml('7-day free trial')
        ->assertSeeInOrderInHtml(['How to activate a till', 'download and install SSPOS', 'enter the licence key', 'ready to trade'])
        ->assertSeeInText('Station Road – Back till: SSP-DDDD-EEEE-FFFF-2222');

    expect($mail->logMeta())->toBe(['business' => 'Khan Mini Mart', 'tills' => 2, 'branches' => 2, 'trial_days' => 7]);
});

it('explains a set-password link and a reset link differently', function () {
    $setup = SetPasswordMail::sample();
    $setup->assertSeeInHtml('Set your password')
        ->assertSeeInHtml('Khan Mini Mart')
        ->assertSeeInHtml('expires in 1 hour')
        ->assertSeeInHtml('/reset-password/sample-token')
        ->assertDontSeeInHtml('you can ignore this email');

    $reset = new SetPasswordMail(new SetPasswordData('Aisha Khan', 'a@example.test', 'https://x.test/reset-password/abc', 30, firstTime: false));
    expect($reset->subjectLine())->toBe('Reset your Switch & Save password');
    $reset->assertSeeInHtml('Reset your password')->assertSeeInHtml('expires in 30 minutes')->assertSeeInHtml('you can ignore this email');
});

it('covers what happens next and how to pay in trial emails', function () {
    TrialReminderMail::sample()
        ->assertSeeInHtml('What happens next')
        ->assertSeeInHtml('We take payment in cash')
        ->assertSeeInHtml('£25.00 per till per month')
        ->assertSeeInHtml('3 tills');

    TrialEndedMail::sample()
        ->assertSeeInHtml('Your free trial has ended')
        ->assertSeeInHtml('stop taking sales')
        ->assertSeeInHtml('We take payment in cash');
});

it('lists renewed tills with the new expiry in Europe/London dates', function () {
    Carbon::setTestNow('2026-09-24 10:00:00');
    $expiry = Carbon::parse('2026-10-31 23:30:00', 'UTC'); // 31 Oct 23:30 UTC is still 31 Oct in London (GMT)

    $mail = new LicenceRenewedMail(new LicenceRenewedData(
        'Khan Mini Mart', 'Aisha Khan',
        [new RenewedTillData('High Street', 'Till 1', $expiry)],
        $expiry, amountPaid: '1234.5', reference: 'INV-0042',
    ));

    expect($mail->subjectLine())->toBe('Your Switch & Save licence is renewed until 31 October 2026');
    $mail->assertSeeInHtml('High Street')->assertSeeInHtml('£1,234.50')->assertSeeInHtml('INV-0042')->assertSeeInHtml('1 till');
});

it('gives the suspension reason and how to fix it', function () {
    $mail = new AccountSuspendedMail(new AccountSuspendedData('Khan Mini Mart', 'Aisha Khan', 'Invoice INV-9 is overdue.', now(), amountDue: '50'));

    $mail->assertSeeInHtml('Invoice INV-9 is overdue.')
        ->assertSeeInHtml('How to fix it')
        ->assertSeeInHtml('£50.00')
        ->assertSeeInHtml('help@switchandsave.test')
        ->assertSeeInHtml('0113 496 0000');

    $custom = new AccountSuspendedMail(new AccountSuspendedData('Khan Mini Mart', 'Aisha Khan', 'Card chargeback.', now(), howToFix: 'Call us to talk it through.'));
    $custom->assertSeeInHtml('Call us to talk it through.')->assertDontSeeInHtml('Pay the amount due');
});

it('confirms reactivation', function () {
    AccountReactivatedMail::sample()->assertSeeInHtml('active again')->assertSeeInHtml('3 tills')->assertSeeInHtml('valid until');
});

it('sends new lead alerts to the staff inbox with a link to leads', function () {
    $mail = AdminNewLeadMail::sample();

    $mail->assertTo('team@switchandsave.test')
        ->assertSeeInHtml('Imran Patel')
        ->assertSeeInHtml('imran@patelnews.co.uk')
        ->assertSeeInHtml('07700 900123')
        ->assertSeeInHtml('https://portal.example.test/admin/leads')
        ->assertSeeInHtml('Leeds shop');

    expect(AdminNewLeadMail::audience())->toBe('staff')
        ->and($mail->logMeta())->not->toHaveKeys(['email', 'phone']);
});

it('replies to support on customer emails', function () {
    WelcomeTenantMail::sample()->assertHasReplyTo('help@switchandsave.test');
});

it('security review L3: a visitor\'s Markdown in the new-lead email renders as plain text, never a link', function () {
    $mail = new AdminNewLeadMail(new NewLeadData(
        contactName: '[Imran](https://evil.test/a)',
        businessName: '**Urgent** <b>shop</b>',
        email: 'imran@patelnews.co.uk',
        phone: null,
        shops: 1,
        tills: 1,
        receivedAt: now(),
        message: "# Reset your password\n\n[Click here](https://evil.test/login) - it's quick. Thanks!",
    ));
    $html = $mail->render();

    expect($html)->not->toContain('href="https://evil.test')
        ->not->toContain('<strong>Urgent</strong>')
        ->not->toContain('<b>shop</b>')
        ->not->toContain('<h1>Reset your password</h1>')
        ->not->toContain('\\[')
        ->toContain('[Click here](https://evil.test/login)')
        ->toContain('[Imran](https://evil.test/a)')
        ->toContain('Thanks!');
});
