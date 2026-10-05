<?php

use App\Domain\Billing\Data\BillingStatusData;
use App\Domain\Billing\Enums\InvoiceKind;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Enums\PaymentMethod;
use App\Domain\Billing\GoCardless\Enums\PaymentStatus;
use App\Domain\Billing\GoCardless\Models\GoCardlessPayment;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Demo\Billing\BuildDemoBillingBusiness;
use App\Domain\Demo\Billing\DemoBillingScenario;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Mail\Enums\EmailStatus;
use App\Domain\Mail\Models\EmailLog;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Billing\GoCardless\GoCardlessTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class, GoCardlessTestHelpers::class);

/*
 * demo:billing: one demo business per billing case, made through the real actions with the clock set back. Each is
 * checked for its state, its dates and its rows; nothing may reach GoCardless or send an email.
 */
beforeEach(function () {
    $this->withoutVite();
    $this->atLondon('2026-10-05 10:00');
    $this->setVat(true);
    config(['billing.suspend_after_days' => 7, 'mail.default' => 'array']); // as on the server
    $this->gc = $this->fakeGoCardless();
});

function demoBusiness(DemoBillingScenario $scenario): Company
{
    return app(BuildDemoBillingBusiness::class)->handle($scenario);
}

/** @return array<string, mixed> */
function demoStatus(Company $company): array
{
    return BillingStatusData::for(BillingStatus::for($company->refresh()));
}

function sentMessages(): int
{
    return app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
}

test('setup + monthly (default): setup fee by bank transfer, Direct Debit active, last month collected, next collection', function () {
    $company = demoBusiness(DemoBillingScenario::SetupMonthly);
    $status = demoStatus($company);
    $account = $this->billingAccountOf($company);
    $setup = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', InvoiceKind::SetupFee->value)->sole();
    $month = Invoice::withoutCompanyScope()->where('company_id', $company->id)->where('kind', '!=', InvoiceKind::SetupFee->value)->sole();

    expect($company->name)->toBe('DEMO – Setup + monthly')
        ->and($company->is_demo)->toBeTrue()
        ->and($status['state'])->toBe(BillingStatus::PAID)
        ->and($status['headline'])->toBe('All paid')
        ->and($status['planType']['value'])->toBe('setupAndRecurring')
        ->and($status['setupFee']['text'])->toBe('£1,440.00 — paid by bank transfer on 31 Aug 2026')
        ->and($status['recurring']['text'])->toBe('£14.40 a month (1 till × £12.00 + VAT) by Direct Debit — mandate active')
        ->and($status['next'])->toBe(['text' => 'Next Direct Debit: £14.40 on 9 Oct 2026.', 'date' => '2026-10-09'])
        ->and($status['action'])->toBeNull()
        ->and($setup->status)->toBe(InvoiceStatus::Paid)->and($setup->total)->toBe('1440.00')
        ->and($month->status)->toBe(InvoiceStatus::Paid)->and($month->total)->toBe('14.40')
        ->and($month->number)->toStartWith('DEMO-INV-')
        ->and(Payment::withoutCompanyScope()->where('company_id', $company->id)->pluck('method')->map->value->sort()->values()->all())->toBe([PaymentMethod::BankTransfer->value, PaymentMethod::DirectDebit->value])
        ->and(GoCardlessPayment::withoutCompanyScope()->where('company_id', $company->id)->sole()->status)->toBe(PaymentStatus::Confirmed)
        ->and($account->gc_mandate_id)->toStartWith('DEMO-MD-')
        ->and($account->gc_subscription_id)->toStartWith('DEMO-SB-')
        ->and($account->gc_next_charge_date?->format('Y-m-d'))->toBe('2026-10-09')
        ->and(Licence::withoutCompanyScope()->where('company_id', $company->id)->sole()->activated_at)->toBeNull()
        // Demo documents have their own numbers: the real sequences have no gap.
        ->and($this->sequenceValue('invoice'))->toBe(0)
        ->and($this->sequenceValue('payment'))->toBe(0);
});

test('setup only, paid by card: a full licence for 10 years, nothing more to pay', function () {
    $company = demoBusiness(DemoBillingScenario::SetupOnlyPaid);
    $status = demoStatus($company);

    expect($status['state'])->toBe(BillingStatus::PAID)
        ->and($status['setupFee']['text'])->toBe('£1,440.00 — paid by card on 15 Sep 2026')
        ->and($status['recurring']['status'])->toBe('none')
        ->and($status['next']['text'])->toBe('Nothing more to pay. The licence runs to 15 Sep 2036 and renews itself.')
        ->and($company->status)->toBe(CompanyStatus::Active)
        ->and($this->billingAccountOf($company)->gc_mandate_id)->toBeNull();
});

test('setup only, not paid: on trial with the days left and the day the tills lock', function () {
    $status = demoStatus(demoBusiness(DemoBillingScenario::SetupOnlyUnpaid));

    expect($status['state'])->toBe(BillingStatus::TRIAL)
        ->and($status['headline'])->toBe('Trial — 4 days left')
        ->and($status['daysLeft'])->toBe(4)
        ->and($status['locksOn'])->toBe('2026-10-12') // trial end 9 Oct + 3 trial grace days
        ->and($status['setupFee']['status'])->toBe('unpaid')
        ->and($status['action'])->toBe('recordSetupPayment')
        ->and($status['next']['text'])->toContain('The trial ends on 9 Oct 2026. Unless the setup fee (£1,440.00) is paid');
});

test('waiting for Direct Debit: setup paid in cash, inside the 3-day deadline, reminder sent, lock date', function () {
    $company = demoBusiness(DemoBillingScenario::WaitingForDirectDebit);
    $status = demoStatus($company);
    $account = $this->billingAccountOf($company);

    expect($status['state'])->toBe(BillingStatus::WAITING_FOR_DD)
        ->and($status['headline'])->toBe('Waiting for Direct Debit — locks on 7 Oct 2026')
        ->and($status['locksOn'])->toBe('2026-10-07') // deadline 6 Oct 08:00: the 06:00 run on 7 Oct suspends
        ->and($status['setupFee']['text'])->toContain('paid by cash')
        ->and($status['next']['text'])->toContain('by 6 Oct 2026, 08:00 (reminder email sent)')
        ->and($status['action'])->toBe('sendDirectDebitLink')
        ->and($account->mandate_reminder_for?->equalTo($account->mandate_deadline_at))->toBeTrue()
        ->and(EmailLog::query()->where('template', 'direct-debit-setup')->sole()->status)->toBe(EmailStatus::Suppressed);
});

test('payment failed 4 days ago: overdue, 3 days before the tills lock', function () {
    $company = demoBusiness(DemoBillingScenario::PaymentFailed);
    $status = demoStatus($company);
    $failed = GoCardlessPayment::withoutCompanyScope()->where('company_id', $company->id)->where('status', PaymentStatus::Failed->value)->sole();

    expect($status['state'])->toBe(BillingStatus::PAYMENT_FAILED)
        ->and($status['headline'])->toBe('Payment failed — locks on 8 Oct 2026 if unpaid')
        ->and($status['daysLeft'])->toBe(3)
        ->and($status['action'])->toBe('retryPayment')
        ->and($status['failedPaymentId'])->toBe($failed->id)
        ->and($status['planType']['value'])->toBe('recurringOnly')
        ->and($failed->invoice?->status)->toBe(InvoiceStatus::Overdue)
        ->and($failed->charge_date?->format('Y-m-d'))->toBe('2026-09-30')
        ->and($company->status)->toBe(CompanyStatus::Overdue)
        ->and(GoCardlessPayment::withoutCompanyScope()->where('company_id', $company->id)->where('status', PaymentStatus::Confirmed->value)->count())->toBe(1);

    // What happens next comes true: the 06:00 run on 8 Oct suspends it.
    $this->runBillingOn('2026-10-07');
    expect($company->refresh()->status)->toBe(CompanyStatus::Overdue);
    $this->runBillingOn('2026-10-08');
    expect($company->refresh()->status)->toBe(CompanyStatus::Suspended)
        ->and(demoStatus($company)['state'])->toBe(BillingStatus::SUSPENDED);
});

test('setup fee in 2 instalments: one paid, one due next month', function () {
    $status = demoStatus(demoBusiness(DemoBillingScenario::Instalments));

    expect($status['state'])->toBe(BillingStatus::INSTALMENT_DUE)
        ->and($status['headline'])->toBe('Setup fee part paid — £720.00 left')
        ->and($status['setupFee']['text'])->toBe('£720.00 paid (1 of 2 instalments) by bank transfer on 15 Sep 2026 — £720.00 left, next due 15 Oct 2026')
        ->and($status['next']['date'])->toBe('2026-10-15')
        ->and($status['action'])->toBe('recordSetupPayment');
});

test('every scenario ends in the state it promises', function (DemoBillingScenario $scenario) {
    expect(demoStatus(demoBusiness($scenario))['state'])->toBe($scenario->expectedState());
})->with(DemoBillingScenario::cases());

test('demo businesses never reach GoCardless and never get an email, through billing:run and the reconcile too', function () {
    $this->artisan('demo:billing', ['--scenario' => 'all'])->assertSuccessful();

    $this->artisan('billing:run')->assertSuccessful();
    $this->artisan('billing:reconcile-gocardless')->assertSuccessful();

    expect(Company::query()->where('is_demo', true)->count())->toBe(6)
        ->and($this->gc->calls)->toBe([])
        ->and(sentMessages())->toBe(0)
        ->and(EmailLog::query()->count())->toBeGreaterThan(0)
        ->and(EmailLog::query()->where('status', '!=', EmailStatus::Suppressed->value)->count())->toBe(0);
});

test('a real business still gets its emails', function () {
    $company = $this->payingTenant();
    $this->issuedFor($company);

    expect(sentMessages())->toBeGreaterThan(0)
        ->and(EmailLog::query()->where('status', EmailStatus::Suppressed->value)->count())->toBe(0);
});

test('the command makes only the default business, and repeats without duplicates', function () {
    $this->artisan('demo:billing')->expectsOutputToContain('never sent to GoCardless')->assertSuccessful();
    $this->artisan('demo:billing')->assertSuccessful();

    expect(Company::query()->where('is_demo', true)->pluck('name')->all())->toBe(['DEMO – Setup + monthly']);
});

test('an unknown scenario is refused', function () {
    $this->artisan('demo:billing', ['--scenario' => 'nope'])->assertExitCode(2);

    expect(Company::query()->where('is_demo', true)->count())->toBe(0);
});

test('--fresh removes the demo businesses and only them', function () {
    $real = $this->payingTenant('Khan Mini Mart', 1, 'KHN');
    $this->pay($real, '10.00', []);
    $realInvoice = $this->issuedFor($real);
    $count = fn (string $table, string $companyId) => DB::table($table)->where('company_id', $companyId)->count();
    $before = collect(['licences', 'invoices', 'payments', 'company_user', 'branches', 'registers', 'audit_logs'])->mapWithKeys(fn ($t) => [$t => $count($t, $real->id)])->all();

    $this->artisan('demo:billing', ['--scenario' => 'all'])->assertSuccessful();
    $old = Company::query()->where('is_demo', true)->pluck('id')->all();
    $oldUsers = User::query()->where('email', 'like', '%@%.example.invalid')->pluck('id')->all();

    $this->artisan('demo:billing', ['--fresh' => true])->expectsOutputToContain('Removed 6 demo businesses')->assertSuccessful();

    expect(Company::withTrashed()->whereIn('id', $old)->count())->toBe(0)
        ->and(DB::table('invoices')->whereIn('company_id', $old)->count())->toBe(0)
        ->and(DB::table('gocardless_payments')->whereIn('company_id', $old)->count())->toBe(0)
        ->and(DB::table('licences')->whereIn('company_id', $old)->count())->toBe(0)
        ->and(User::query()->whereIn('id', $oldUsers)->count())->toBe(0)
        ->and(Company::query()->where('is_demo', true)->pluck('name')->all())->toBe(['DEMO – Setup + monthly'])
        ->and($real->refresh()->exists)->toBeTrue()
        ->and(Invoice::withoutCompanyScope()->find($realInvoice->id))->not->toBeNull()
        ->and(collect($before)->map(fn ($n, $t) => $count($t, $real->id))->all())->toBe($before);
});

test('production refuses without --force, and is safe with it', function () {
    app()->detectEnvironment(fn () => 'production');

    $this->artisan('demo:billing')->expectsOutputToContain('add --force')->assertFailed();
    expect(Company::query()->where('is_demo', true)->count())->toBe(0);

    $this->artisan('demo:billing', ['--force' => true])->assertSuccessful();
    expect(Company::query()->where('is_demo', true)->count())->toBe(1)
        ->and($this->gc->calls)->toBe([]);
});

test('the admin Billing tab and the portal show the same Billing status card', function () {
    $company = demoBusiness(DemoBillingScenario::WaitingForDirectDebit);

    $this->actingAs($this->admin(), 'admin')->get(route('admin.tenants.show', $company))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/tenants/show')
            ->where('billing.status.state', BillingStatus::WAITING_FOR_DD)
            ->where('billing.status.demo', true)
            ->where('billing.status.action', 'sendDirectDebitLink')
            ->where('billing.status.next.text', fn (string $text) => str_starts_with($text, 'The owner must set up the Direct Debit')));

    $this->actingAs($this->ownerOf($company), 'web')->get('/app/billing')->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('app/billing')
            ->where('status.state', BillingStatus::WAITING_FOR_DD)
            ->where('status.headline', 'Waiting for Direct Debit — locks on 7 Oct 2026')
            ->where('status.next.text', fn (string $text) => str_starts_with($text, 'Set up your Direct Debit by')));
});

test('the admin overview filters businesses by billing state', function () {
    demoBusiness(DemoBillingScenario::SetupMonthly);
    demoBusiness(DemoBillingScenario::PaymentFailed);
    demoBusiness(DemoBillingScenario::WaitingForDirectDebit);

    $this->actingAs($this->admin(), 'admin')->get(route('admin.billing.index', ['state' => 'overdue']))->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('admin/billing/overview')
            ->where('businesses.counts.paid', 1)
            ->where('businesses.counts.overdue', 1)
            ->where('businesses.counts.waitingForDirectDebit', 1)
            ->where('businesses.group', 'overdue')
            ->has('businesses.rows', 1)
            ->where('businesses.rows.0.name', 'DEMO – Direct Debit failed')
            ->where('businesses.rows.0.state', BillingStatus::PAYMENT_FAILED));
});

test('the next date follows the clock: a day later the trial has a day less', function () {
    $company = demoBusiness(DemoBillingScenario::SetupOnlyUnpaid);
    $this->travelTo(CarbonImmutable::now()->addDay());

    expect(demoStatus($company)['headline'])->toBe('Trial — 3 days left');
});

test('a lock day the billing run has not acted on yet shows as the next run, never in the past', function () {
    $company = $this->payingTenant();
    $invoice = $this->issuedFor($company);
    $invoice->forceFill(['due_date' => '2026-09-01', 'status' => InvoiceStatus::Overdue])->save();

    $status = demoStatus($company);

    expect($status['state'])->toBe(BillingStatus::OVERDUE)
        ->and($status['locksOn'])->toBe('2026-10-06') // the 06:00 run tomorrow
        ->and($status['action'])->toBe('recordPayment');
});

test('a demo invoice is not counted as emailed (its email is only logged)', function () {
    $company = demoBusiness(DemoBillingScenario::SetupMonthly);

    expect(Invoice::withoutCompanyScope()->where('company_id', $company->id)->sum('sent_count'))->toEqual(0);
});
