<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Mail\Actions\UpdateEmailSettings;
use App\Domain\Mail\Enums\EmailCategory;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Mailables\AdminNewLeadMail;
use App\Domain\Mail\Mailables\CustomerStatementMail;
use App\Domain\Mail\Mailables\DirectDebitFailedMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Mail\Mailables\OwnerDigestMail;
use App\Domain\Mail\Mailables\PaymentReminderMail;
use App\Domain\Mail\Mailables\PortalInvitationMail;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Mailables\WelcomeTenantMail;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Mail\Models\HeldEmail;
use App\Domain\Mail\Support\EmailControl;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

/*
 * P11 (owner 2026-10-07): tenant emails go out by themselves only when the admin wants. A category switched off holds
 * its emails (logged "held") until an admin sends or discards them; billing itself is unchanged; mail a user asks
 * for, mail a tenant sends and our own staff mail always go.
 */
beforeEach(function () {
    $this->withoutVite();
    config(['mail.default' => 'array']);
    $this->atLondon('2026-10-01 10:00');
});

/** Messages the array mailer actually sent, as "to: subject". */
function sentMail(): array
{
    return collect(app('mail.manager')->mailer('array')->getSymfonyTransport()->messages())
        ->map(fn ($message) => $message->getEnvelope()->getRecipients()[0]->getAddress().': '.$message->getOriginalMessage()->getSubject())
        ->values()->all();
}

function holdAll(): void
{
    app(UpdateEmailSettings::class)->handle(array_fill_keys(EmailCategory::values(), false));
}

function heldOf(Company $company): array
{
    return HeldEmail::query()->waiting()->where('company_id', $company->id)->orderBy('created_at')->get()->all();
}

test('by default every email goes as before, and nothing is held', function () {
    $company = $this->licensedTenant('Khan Mini Mart', 2);

    expect(EmailControl::all())->toBe(array_fill_keys(EmailCategory::values(), true))
        ->and(sentMail())->toContain('khan-mini-mart@owner.test: Welcome to Switch & Save – your licence keys')
        ->and(sentMail())->toContain('khan-mini-mart@owner.test: Set your Switch & Save password')
        ->and(HeldEmail::query()->count())->toBe(0)
        ->and(EmailLog::query()->where('company_id', $company->id)->where('status', EmailStatus::Held)->count())->toBe(0);
});

test('switched off: the welcome and set-password emails are held with what they will send, then sent from the business page', function () {
    holdAll();
    $company = $this->licensedTenant('Khan Mini Mart', 2);

    expect(sentMail())->toBe([])
        ->and(heldOf($company))->toHaveCount(2)
        ->and(EmailLog::query()->where('company_id', $company->id)->pluck('status')->unique()->values()->all())->toBe([EmailStatus::Held]);

    $owner = $this->admin();
    $this->actingAs($owner, 'admin')->get(route('admin.tenants.show', $company))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page
            ->has('emails.held', 2)
            ->where('emails.welcomeHeld', true)
            ->where('emails.passwordHeld', true)
            ->where('emails.held.0.to', 'khan-mini-mart@owner.test')
            ->where('emails.held.0.facts', fn ($facts) => collect($facts)->pluck('label')->contains('Licence keys')
                && collect($facts)->firstWhere('label', 'Tills')['value'] === '2'));

    $this->actingAs($owner, 'admin')->post(route('admin.tenants.emails.welcome', $company))->assertSessionHas('success');
    expect(sentMail())->toBe(['khan-mini-mart@owner.test: Welcome to Switch & Save – your licence keys'])
        ->and(EmailLog::query()->where('template', 'welcome-tenant')->sole()->status)->toBe(EmailStatus::Sent)
        ->and(AuditLog::query()->where('action', 'email.held_sent')->count())->toBe(1);

    // The welcome email is gone from the queue: asking again explains how to send keys instead.
    $this->actingAs($owner, 'admin')->post(route('admin.tenants.emails.welcome', $company))->assertSessionHasErrors('email');

    // The set-password link goes with a fresh 7-day link.
    $this->actingAs($owner, 'admin')->post(route('admin.tenants.emails.password-link', $company))->assertSessionHas('success');
    expect(sentMail())->toHaveCount(2)
        ->and(heldOf($company))->toBe([]);
});

test('switched off: an issued invoice is held (billing goes on), and "Email to customer" sends it as it stands', function () {
    app(UpdateEmailSettings::class)->handle([EmailCategory::Invoices->value => false]);
    $company = $this->payingTenant('Khan Mini Mart', 1);
    $invoice = $this->issuedFor($company);

    expect($invoice->number)->not->toBeNull()
        ->and($this->fresh($invoice)->sent_count)->toBe(0)
        ->and(collect(sentMail())->filter(fn ($line) => str_contains($line, 'Invoice'))->all())->toBe([])
        ->and(AuditLog::query()->where('action', 'invoice.sent')->sole()->meta)->toMatchArray(['held' => true]);

    $admin = $this->admin(AdminRole::Accounts);
    $this->actingAs($admin, 'admin')->get(route('admin.billing.invoices.show', $invoice->id))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->where('heldEmails', 1));

    $this->actingAs($admin, 'admin')->post(route('admin.billing.invoices.send', $invoice->id))->assertSessionHas('success');

    expect(collect(sentMail())->filter(fn ($line) => str_contains($line, $invoice->number))->count())->toBe(1)
        ->and($this->fresh($invoice)->sent_count)->toBe(1)
        ->and(heldOf($company))->toBe([]);

    // Sent again later: a normal manual send, never held.
    $this->actingAs($admin, 'admin')->post(route('admin.billing.invoices.send', $invoice->id))->assertSessionHas('success', "{$invoice->number} sent again to 1 address.");
    expect($this->fresh($invoice)->sent_count)->toBe(2);
});

test('a held email can be sent or discarded once, each audited; a held invoice later voided cannot be sent', function () {
    app(UpdateEmailSettings::class)->handle([EmailCategory::Invoices->value => false]);
    $company = $this->payingTenant('Khan Mini Mart', 1);
    $first = $this->issuedFor($company);
    $second = $this->issuedFor($company, new NewInvoice(periodStart: BillingDates::date('2026-12-01')));
    [$heldFirst, $heldSecond] = heldOf($company);
    $admin = $this->admin(AdminRole::Accounts);

    $this->actingAs($admin, 'admin')->post(route('admin.emails.held.discard', $heldFirst->id))->assertSessionHas('success');
    expect(EmailLog::query()->find($heldFirst->email_log_id)->status)->toBe(EmailStatus::Discarded)
        ->and($heldFirst->refresh()->payload)->toBeNull()
        ->and(AuditLog::query()->where('action', 'email.held_discarded')->sole()->actor_id)->toBe($admin->id);
    $this->actingAs($admin, 'admin')->post(route('admin.emails.held.send', $heldFirst->id))->assertSessionHasErrors('email');

    $this->voidIt($second);
    $this->actingAs($admin, 'admin')->post(route('admin.emails.held.send', $heldSecond->id))->assertSessionHasErrors('email');
    $this->actingAs($admin, 'admin')->post(route('admin.tenants.emails.send-held', $company))->assertSessionHasErrors('email');

    expect(collect(sentMail())->filter(fn ($line) => str_contains($line, 'Invoice'))->all())->toBe([])
        ->and(Invoice::withoutCompanyScope()->find($first->id)->sent_count)->toBe(0);
});

test('"Send all held emails" sends every held email of the business, oldest first', function () {
    holdAll();
    $company = $this->payingTenant('Khan Mini Mart', 1);
    $this->issuedFor($company);
    $other = $this->licensedTenant('Other Shop', 1, 'OTH');
    expect(heldOf($company))->toHaveCount(3);

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')->post(route('admin.tenants.emails.send-held', $company))
        ->assertSessionHas('success', '3 emails sent for Khan Mini Mart.');

    expect(sentMail())->toHaveCount(3)
        ->and(heldOf($company))->toBe([])
        ->and(heldOf($other))->toHaveCount(2)
        ->and(AuditLog::query()->where('action', 'email.held_sent')->count())->toBe(3);
});

test('mail a person asks for, tenants send and our staff mail always go, whatever is held', function () {
    holdAll();
    $user = User::factory()->create(['email' => 'aisha@khan.test']);

    $user->sendPasswordResetNotification('token-123');
    Mail::to('customer@example.test')->queue(CustomerStatementMail::sample());
    Mail::to('invitee@example.test')->queue(PortalInvitationMail::sample());
    Mail::queue(AdminNewLeadMail::sample());
    Mail::to((string) config('sspos.staff_email'))->queue(DirectDebitFailedMail::sample());

    expect(sentMail())->toHaveCount(5)
        ->and(collect(sentMail())->first(fn ($line) => str_starts_with($line, 'aisha@khan.test')))->toContain('Reset your Switch & Save password')
        ->and(HeldEmail::query()->count())->toBe(0);

    // The same Direct Debit email to the customer is held.
    Mail::to('owner@example.test')->queue(DirectDebitFailedMail::sample());
    Mail::to('owner@example.test')->queue(OwnerDigestMail::sample());
    expect(HeldEmail::query()->pluck('category')->map->value->all())->toBe(['reminders', 'ownerAlerts'])
        ->and(sentMail())->toHaveCount(5);
});

test('an admin’s own sends are never held', function () {
    holdAll();
    $company = $this->licensedTenant('Khan Mini Mart', 1);
    $owner = $company->owners()->firstOrFail();

    $this->actingAs($this->admin(), 'admin')->post(route('admin.tenants.users.password-link', [$company, $owner->id]))->assertSessionHas('success');

    expect(sentMail())->toBe(['khan-mini-mart@owner.test: Set your Switch & Save password'])
        // One from onboarding (that email is held), one for this send.
        ->and(AuditLog::query()->where('action', 'company.user_password_link_sent')->count())->toBe(2)
        ->and(HeldEmail::query()->where('template', 'set-password')->count())->toBe(1);
});

test('the email settings page: owner and accounts only, saves and audits each change', function () {
    $this->actingAs($this->admin(AdminRole::Support), 'admin')->get(route('admin.settings.emails'))->assertForbidden();
    $this->actingAs($this->admin(AdminRole::Support), 'admin')->put(route('admin.settings.emails.update'), ['settings' => ['welcome' => false]])->assertForbidden();
    $this->actingAs($this->admin(AdminRole::Sales), 'admin')->post(route('admin.tenants.emails.send-held', $this->licensedTenant()))->assertForbidden();

    $accounts = $this->admin(AdminRole::Accounts);
    $this->actingAs($accounts, 'admin')->get(route('admin.settings.emails'))->assertOk()
        ->assertInertia(fn (AssertableInertia $page) => $page->component('admin/settings/emails')->has('categories', 5)->where('categories.0.sendAutomatically', true));

    $this->actingAs($accounts, 'admin')->put(route('admin.settings.emails.update'), ['settings' => ['welcome' => false, 'invoices' => true]])
        ->assertSessionHas('success', 'Email settings saved. 1 kind of email is held for you to send.');

    $log = AuditLog::query()->where('action', 'email.settings_updated')->sole();
    expect(EmailControl::sendsAutomatically(EmailCategory::Welcome))->toBeFalse()
        ->and($log->before)->toBe(['welcome' => true])->and($log->after)->toBe(['welcome' => false])
        ->and($log->actor_id)->toBe($accounts->id);

    $this->actingAs($accounts, 'admin')->put(route('admin.settings.emails.update'), ['settings' => 'yes'])->assertSessionHasErrors('settings');
    $this->get('/admin/settings/emails')->assertOk();
});

test('emails:auto shows and switches the categories (all off for the owner’s check, back on one by one)', function () {
    $this->artisan('emails:auto', ['--off' => 'all'])->expectsOutputToContain('No: held for an admin')->assertSuccessful();
    expect(array_values(EmailControl::all()))->toBe([false, false, false, false, false]);

    $this->artisan('emails:auto', ['--on' => 'invoices,reminders'])->assertSuccessful();
    expect(EmailControl::all())->toMatchArray(['invoices' => true, 'reminders' => true, 'welcome' => false]);

    $this->artisan('emails:auto', ['--off' => 'birthdays'])->assertFailed();
    $this->artisan('emails:auto')->expectsOutputToContain('Welcome and licence keys')->assertSuccessful();
});

test('a held preview shows the email as it will go, without a password link', function () {
    holdAll();
    $company = $this->licensedTenant('Khan Mini Mart', 1);
    $link = HeldEmail::query()->where('template', SetPasswordMail::templateKey())->sole();
    $welcome = HeldEmail::query()->where('template', WelcomeTenantMail::templateKey())->sole();
    $admin = $this->admin(AdminRole::Accounts);

    $this->actingAs($admin, 'admin')->get(route('admin.emails.held.preview', $link->id))->assertOk()
        ->assertSee('a-new-link-is-made-when-sent')->assertDontSee('reset-password/'.'token');
    $this->actingAs($admin, 'admin')->get(route('admin.emails.held.preview', $welcome->id))->assertOk()
        ->assertSee($company->name)->assertHeader('Content-Security-Policy');
    expect(sentMail())->toBe([]);
});

test('Pakistan: a manual-billing payment reminder is held when reminders are off', function () {
    config(['country.code' => 'PK']);
    app()->forgetInstance(Country::class);
    app(UpdateEmailSettings::class)->handle([EmailCategory::Reminders->value => false]);

    Mail::to('owner@example.pk')->queue(PaymentReminderMail::sample());
    Mail::to('owner@example.pk')->queue(InvoiceMail::sample());

    expect(HeldEmail::query()->sole()->template)->toBe('payment-reminder')
        ->and(sentMail())->toHaveCount(1);
})->group('country-pk');
