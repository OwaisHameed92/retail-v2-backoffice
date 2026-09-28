<?php

use App\Domain\Billing\Actions\UpdateBillingSettings;
use App\Domain\Billing\Data\BillingSettingsInput;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\SetupFeeMethod;
use App\Domain\Billing\GoCardless\Actions\ChargeSetupFee;
use App\Domain\Billing\GoCardless\Enums\MandateStatus;
use App\Domain\Billing\GoCardless\Enums\SubscriptionStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\GoCardless\Support\SetupFee;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Mail\Mailables\DirectDebitSetupMail;
use App\Domain\Mail\Mailables\InvoiceMail;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\AddRegister;
use App\Domain\Tenancy\Actions\DeactivateRegister;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\URL;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);
    $this->fakeGoCardless();
});

test('the setup fee is the plan fee unless the business has an override, plus VAT', function () {
    $this->standardPlan()->forceFill(['setup_fee' => '349.00'])->save();
    $company = $this->directDebitTenant();
    $account = $this->billingAccountOf($company);

    expect(SetupFee::planFee($company))->toBe('349.00')
        ->and(SetupFee::totals($company, $account))->toBe(['net' => '349.00', 'vat' => '69.80', 'gross' => '418.80']);

    $account->setup_fee_override = '99.00';
    $account->save();

    expect(SetupFee::totals($company, $this->billingAccountOf($company)))->toBe(['net' => '99.00', 'vat' => '19.80', 'gross' => '118.80']);

    $account->setup_fee_override = '0.00';
    $account->save();

    expect(SetupFee::schedule($company, $this->billingAccountOf($company)))->toBe([]);
});

test('instalments split the quoted total into equal monthly parts, the last one taking the pennies', function () {
    $company = $this->directDebitTenant(setupFee: '349.00', instalments: 3);
    $account = $this->billingAccountOf($company);

    expect(SetupFee::schedule($company, $account))->toBe([
        ['net' => '116.33', 'vat' => '23.27', 'gross' => '139.60'],
        ['net' => '116.33', 'vat' => '23.27', 'gross' => '139.60'],
        ['net' => '116.33', 'vat' => '23.27', 'gross' => '139.60'],
    ])->and(SetupFee::totals($company, $account)['gross'])->toBe('418.80');

    $account->setup_fee_override = '100.00';
    $account->setup_fee_instalments = 7;
    $account->save();
    $schedule = SetupFee::schedule($company, $this->billingAccountOf($company));

    expect(array_column($schedule, 'gross'))->toBe(['17.14', '17.14', '17.14', '17.14', '17.14', '17.14', '17.16']);
});

test('a manual setup fee is invoiced and emailed straight away, instalments a month apart', function () {
    $company = $this->directDebitTenant(setupFee: '100.00', instalments: 2, method: SetupFeeMethod::Manual);

    $invoices = app(ChargeSetupFee::class)->handle($company);

    expect($invoices)->toHaveCount(2)
        ->and($invoices[0]->kind)->toBe(InvoiceKind::SetupFee)
        ->and($invoices[0]->total)->toBe('60.00')
        ->and($invoices[0]->lines->first()->description)->toStartWith('Setup fee, instalment 1 of 2')
        ->and($invoices[1]->due_date->format('Y-m-d'))->toBe('2026-11-24')
        ->and(GoCardlessPayment::withoutCompanyScope()->count())->toBe(0);
    Mail::assertQueued(InvoiceMail::class, 2);

    expect(fn () => app(ChargeSetupFee::class)->handle($company))->toThrow(ValidationException::class);
});

test('the setup email links to a signed page that opens the GoCardless setup', function () {
    $company = $this->directDebitTenant(setupFee: '349.00');

    expect($this->sendSetupEmail($company))->toBe(1);
    Mail::assertQueued(DirectDebitSetupMail::class, function (DirectDebitSetupMail $mail) {
        $this->get($mail->data->setupUrl)->assertRedirect()->assertRedirectContains('gocardless.test/flow/BRQ');

        return $mail->data->setupFee === '418.80' && $mail->data->recurring === '60.00' && $mail->data->tillCount === 2;
    });

    expect($this->billingAccountOf($company)->gc_setup_sent_at)->not->toBeNull()
        ->and(AuditLog::query()->where('action', 'billing.dd_setup_sent')->count())->toBe(1);

    // Unsigned or tampered links are refused.
    $this->get(route('direct-debit.setup', $company))->assertForbidden();
});

test('an upfront business cannot be sent the setup email', function () {
    $company = $this->payingTenant();

    expect(fn () => $this->sendSetupEmail($company))->toThrow(ValidationException::class, 'pays upfront');
});

test('completing the mandate by webhook charges the setup fee and starts the subscription', function () {
    $company = $this->directDebitTenant(setupFee: '349.00');
    $mandateId = $this->setUpMandate($company);
    $account = $this->billingAccountOf($company);

    expect($account->gc_mandate_id)->toBe($mandateId)
        ->and($account->gc_mandate_status)->toBe(MandateStatus::PendingSubmission)
        ->and($account->setup_fee_invoiced_at)->not->toBeNull();

    // Setup fee: one invoice, collected on the mandate's first possible day.
    $setup = Invoice::withoutCompanyScope()->where('kind', InvoiceKind::SetupFee->value)->sole();
    $row = GoCardlessPayment::withoutCompanyScope()->sole();
    expect($setup->total)->toBe('418.80')
        ->and($row->invoice_id)->toBe($setup->id)
        ->and($row->amount)->toBe('418.80')
        ->and($this->gc->payments[$row->gc_payment_id]->amountPence)->toBe(41880)
        ->and($setup->due_date->format('Y-m-d'))->toBe($row->charge_date->format('Y-m-d'));
    Mail::assertQueued(InvoiceMail::class, fn (InvoiceMail $mail) => $mail->data->directDebitOn?->format('Y-m-d') === $row->charge_date->format('Y-m-d'));

    // Subscription: 2 tills × £25.00 + 20% VAT = £60.00 a month, from the next period (1 Nov).
    $subscription = $this->gc->lastSubscription();
    expect($subscription->amountPence)->toBe(6000)
        ->and($subscription->intervalUnit)->toBe('monthly')
        ->and($subscription->upcomingChargeDate)->toBe('2026-11-01')
        ->and($account->gc_subscription_id)->toBe($subscription->id)
        ->and($account->gc_subscription_amount)->toBe('60.00')
        ->and($account->gc_next_charge_date->format('Y-m-d'))->toBe('2026-11-01');

    // The return page and a repeated webhook change nothing.
    $this->get(URL::temporarySignedRoute('direct-debit.done', now()->addHour(), ['company' => $company->id]))->assertOk();
    $this->webhook([$this->gcEvent('mandates', 'active', ['mandate' => $mandateId])])->assertOk();
    expect($this->gc->subscriptions)->toHaveCount(1)
        ->and(GoCardlessPayment::withoutCompanyScope()->count())->toBe(1);
});

test('setup fee instalments are separate Direct Debit payments, each with its invoice', function () {
    $company = $this->directDebitTenant(setupFee: '300.00', instalments: 3);
    $this->setUpMandate($company);

    $rows = GoCardlessPayment::withoutCompanyScope()->orderBy('charge_date')->get();

    expect($rows)->toHaveCount(3)
        ->and($rows->pluck('amount')->all())->toBe(['120.00', '120.00', '120.00'])
        ->and($rows->pluck('instalment')->all())->toBe([1, 2, 3])
        ->and($rows->pluck('invoice_id')->unique())->toHaveCount(3)
        ->and($rows[1]->charge_date->format('Y-m-d'))->toBe($rows[0]->charge_date->addMonthNoOverflow()->format('Y-m-d'));
});

test('the subscription follows the live tills and the billing cycle', function () {
    $company = $this->directDebitTenant();
    $this->setUpMandate($company);
    $subscriptionId = $this->gc->lastSubscription()->id;
    $branch = $this->allowTills($this->branchOf($company));

    app(AddRegister::class)->handle($branch, 'Till 3');

    expect($this->gc->subscriptions[$subscriptionId]->amountPence)->toBe(9000)
        ->and($this->billingAccountOf($company)->gc_subscription_amount)->toBe('90.00')
        ->and(AuditLog::query()->where('action', 'billing.dd_subscription_amount_changed')->sole()->meta)->toMatchArray(['reason' => 'tills']);

    app(DeactivateRegister::class)->handle($this->registerOf($branch, '03'));
    expect($this->gc->subscriptions[$subscriptionId]->amountPence)->toBe(6000);

    // Yearly: a new subscription (interval cannot change) at 2 × £250.00 + VAT.
    $account = $this->billingAccountOf($company);
    app(UpdateBillingSettings::class)->handle($company, new BillingSettingsInput(null, null, [], BillingCycle::Yearly, $account->payment_terms_days, true));

    $new = $this->gc->lastSubscription();
    expect($new->id)->not->toBe($subscriptionId)
        ->and($new->intervalUnit)->toBe('yearly')
        ->and($new->amountPence)->toBe(60000)
        ->and($this->gc->subscriptions[$subscriptionId]->status)->toBe(SubscriptionStatus::Cancelled)
        ->and($this->billingAccountOf($company)->gc_subscription_cycle)->toBe(BillingCycle::Yearly);
});

test('a business without VAT pays the net amount', function () {
    $company = $this->directDebitTenant();
    $this->setVat(true, false, $company);
    $this->setUpMandate($company);

    expect($this->gc->lastSubscription()->amountPence)->toBe(5000);
});
