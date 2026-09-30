<?php

use App\Domain\Billing\Actions\SendBillingRequest;
use App\Domain\Billing\Enums\BillingRequestKind;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Mail\Mailables\AdminSubscriptionRequestMail;
use App\Domain\Mail\Support\EmailTemplates;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/** Module 4.10: My subscription (`/app/billing`): account, setup fee, payments, and requests to cancel or change bank. */
beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
    $this->company = $this->directDebitTenant(setupFee: '120.00', instalments: 3);
    $this->owner = $this->ownerOf($this->company);
});

test('the page shows the account, what is counted and the setup fee instalments still to be charged', function () {
    $this->actingAs($this->owner, 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('app/billing')
        ->where('account.tills', 2)->where('account.shops', 1)->where('account.cancelled', false)
        ->where('setupFee.charged', false)->where('setupFee.method', 'directDebit')->where('setupFee.total', '£144.00')
        ->has('setupFee.parts', 3)->where('setupFee.parts.0.label', 'Part 1 of 3')->where('setupFee.parts.0.amount', '£48.00')
        ->where('payments', [])->where('collections', [])->where('requests', [])->where('canRequest', true));

    $this->actingAs($this->addMember($this->company, CompanyRole::Accountant), 'web')->get('/app/billing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('canRequest', false)->has('setupFee.parts', 3));
});

test('once the Direct Debit is set up the instalments are invoices and GoCardless collections', function () {
    $this->setUpMandate($this->company);

    $this->actingAs($this->owner, 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('setupFee.charged', true)->has('setupFee.parts', 3)->where('setupFee.total', '£144.00')
        ->where('setupFee.parts.2.label', 'Part 3 of 3')
        ->where('collections', fn ($collections) => count($collections) >= 1));
});

test('payments received are listed, newest first, returned ones marked', function () {
    $invoice = $this->issuedFor($this->company);
    $this->pay($this->company, $invoice->total);

    $this->actingAs($this->owner, 'web')->get('/app/billing')->assertInertia(fn (Assert $page) => $page
        ->has('payments', 1)->where('payments.0.method', 'Cash')->where('payments.0.reversed', false));
});

test('the owner asks to cancel: a reason and a tick are needed, staff are alerted, nothing is cancelled', function () {
    $statuses = fn () => Licence::withoutCompanyScope()->where('company_id', $this->company->id)->orderBy('id')->get()->map(fn (Licence $l) => [$l->status, $l->expires_at?->toIso8601String()])->all();
    $before = $statuses();
    $this->actingAs($this->owner, 'web')->post('/app/billing/requests', ['kind' => 'cancel'])->assertSessionHasErrors(['message', 'confirm']);
    $this->actingAs($this->owner, 'web')->post('/app/billing/requests', ['kind' => 'refund'])->assertSessionHasErrors('kind');

    $this->actingAs($this->owner, 'web')->post('/app/billing/requests', ['kind' => 'cancel', 'message' => 'Selling the shop', 'confirm' => true, 'phone' => '07700 900123'])
        ->assertRedirect()->assertSessionHas('success', fn (string $m) => str_contains($m, 'will call you'));
    $this->actingAs($this->owner, 'web')->post('/app/billing/requests', ['kind' => 'cancel', 'message' => 'Still selling', 'confirm' => '1'])
        ->assertSessionHas('success', fn (string $m) => str_contains($m, 'reminded'));

    $alert = LicenceAlert::withoutCompanyScope()->sole();
    expect($alert->type)->toBe(LicenceAlertType::SubscriptionRequested)
        ->and($alert->company_id)->toBe($this->company->id)
        ->and($alert->count)->toBe(2)
        ->and($alert->details['kind'])->toBe('cancel')
        ->and($alert->details['message'])->toBe('Still selling')
        ->and($statuses())->toBe($before)
        ->and($this->company->fresh()->isCancelled())->toBeFalse()
        ->and(AuditLog::query()->where('action', 'billing.request_sent')->count())->toBe(2);

    Mail::assertQueued(AdminSubscriptionRequestMail::class, fn (AdminSubscriptionRequestMail $mail) => $mail->data->kind === 'Cancellation' && $mail->data->phone === '07700 900123');
    $this->actingAs($this->owner, 'web')->get('/app/billing')->assertInertia(fn (Assert $page) => $page
        ->where('requests.0.kind', 'cancel')->where('requests.0.done', false)->where('requests.0.count', 2));

    expect(EmailTemplates::keys())->toContain('admin-subscription-request')
        ->and(AdminSubscriptionRequestMail::sample()->render())->toContain('Cancellation requested');
});

test('a bank account change is its own request and needs no reason', function () {
    $this->actingAs($this->owner, 'web')->post('/app/billing/requests', ['kind' => 'changeBank'])->assertSessionHasNoErrors();

    expect(LicenceAlert::withoutCompanyScope()->sole()->details['kind'])->toBe(BillingRequestKind::ChangeBank->value);
    Mail::assertQueued(AdminSubscriptionRequestMail::class, fn (AdminSubscriptionRequestMail $mail) => $mail->data->kind === 'Bank account change');
});

test('only the owner may send requests; guests go to the login; a cancelled business cannot ask again', function () {
    $this->post('/app/billing/requests', ['kind' => 'changeBank'])->assertRedirect('/login');

    foreach ([CompanyRole::Accountant, CompanyRole::Manager, CompanyRole::Staff] as $role) {
        $this->actingAs($this->addMember($this->company, $role), 'web')->post('/app/billing/requests', ['kind' => 'changeBank'])->assertForbidden();
    }
    expect(LicenceAlert::withoutCompanyScope()->count())->toBe(0);

    $this->company->forceFill(['status' => 'cancelled', 'cancelled_at' => now()])->save();
    expect(fn () => app(SendBillingRequest::class)->handle($this->company->fresh(), $this->owner, BillingRequestKind::Cancel, 'x'))->toThrow(ValidationException::class);
});

test('another business never sees our requests, payments or setup fee', function () {
    $this->actingAs($this->owner, 'web')->post('/app/billing/requests', ['kind' => 'changeBank'])->assertSessionHasNoErrors();
    $other = $this->payingTenant('Other Shop', 1, 'OTH');

    $this->actingAs($this->ownerOf($other), 'web')->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('requests', [])->where('payments', [])->where('setupFee', null)->where('account.tills', 1));
});
