<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Models\BillingAccount;
use App\Domain\Billing\Models\CreditNote;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Models\Payment;
use App\Domain\Billing\Models\PaymentAllocation;
use App\Domain\Billing\Queries\BillingStats;
use App\Domain\Billing\Support\Allocator;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Mail;
use Inertia\Testing\AssertableInertia;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    $this->withoutVite();
    Mail::fake();
    $this->atLondon('2026-10-24 10:00');
    $this->setVat(true);

    // Alpha: £90.00 invoice, £40.00 paid, a £5.00 credit note, billing emails set.
    $this->alpha = $this->payingTenant('Alpha Stores', 3, 'ALP');
    $this->alphaInvoice = $this->issuedFor($this->alpha);
    $this->pay($this->alpha, '40.00');
    $this->creditIt($this->fresh($this->alphaInvoice), '5.00');
    $this->useBillingEmails($this->alpha, ['accounts@alpha.test']);

    // Bravo: £30.00 invoice, £10.00 paid, a £1.00 credit note, billing emails set.
    $this->bravo = $this->payingTenant('Bravo Mart', 1, 'BRV');
    $this->bravoInvoice = $this->issuedFor($this->bravo);
    $this->pay($this->bravo, '10.00');
    $this->creditIt($this->fresh($this->bravoInvoice), '1.00');
    $this->useBillingEmails($this->bravo, ['accounts@bravo.test']);
});

test('billing models only show the current company\'s rows', function (string $model) {
    $counts = [];

    foreach ([$this->alpha, $this->bravo] as $company) {
        $rows = app(CurrentCompany::class)->runAs($company, fn () => $model::query()->get());

        expect($rows)->not->toBeEmpty()
            ->and($rows->pluck('company_id')->unique()->all())->toBe([$company->id]);

        $counts[] = $rows->count();
    }

    expect(array_sum($counts))->toBe($model::withoutCompanyScope()->count());
})->with([
    Invoice::class,
    InvoiceLine::class,
    Payment::class,
    PaymentAllocation::class,
    CreditNote::class,
    BillingAccount::class,
]);

test('another company\'s invoice cannot be found by id inside a company', function () {
    $found = app(CurrentCompany::class)->runAs($this->alpha, fn () => [
        Invoice::query()->find($this->bravoInvoice->id),
        Invoice::query()->find($this->alphaInvoice->id)?->id,
    ]);

    expect($found)->toBe([null, $this->alphaInvoice->id]);
});

test('reading billing models with no current company fails closed', function (string $model) {
    expect(fn () => $model::query()->get())->toThrow(MissingCurrentCompany::class);
})->with([
    Invoice::class,
    InvoiceLine::class,
    Payment::class,
    PaymentAllocation::class,
    CreditNote::class,
    BillingAccount::class,
]);

test('an admin payment for Alpha cannot be put on Bravo\'s invoice', function () {
    $before = Payment::withoutCompanyScope()->count();

    $this->actingAs($this->admin(AdminRole::Accounts), 'admin')
        ->from(route('admin.tenants.show', $this->alpha))
        ->post(route('admin.billing.tenants.payments.store', $this->alpha), [
            'method' => 'cash',
            'amount' => '20.00',
            'received_on' => '2026-10-24',
            'allocation' => 'manual',
            'allocations' => [$this->bravoInvoice->id => '20.00'],
        ])
        ->assertRedirect(route('admin.tenants.show', $this->alpha))
        ->assertSessionHasErrors(['allocations']);

    expect(Payment::withoutCompanyScope()->count())->toBe($before)
        ->and($this->fresh($this->bravoInvoice)->balance)->toBe('19.00')
        ->and($this->fresh($this->alphaInvoice)->balance)->toBe('45.00')
        ->and($this->sequenceValue('payment'))->toBe(2);
});

test('the allocator refuses to cross companies even when called directly', function () {
    $alphaPayment = Payment::withoutCompanyScope()->where('company_id', $this->alpha->id)->firstOrFail();

    expect(fn () => app(Allocator::class)->allocate($alphaPayment, $this->fresh($this->bravoInvoice)))
        ->toThrow(LogicException::class, 'own company');
});

test('Alpha\'s open invoices, billing tab and settings never include Bravo\'s', function () {
    $admin = $this->admin(AdminRole::Accounts);

    $this->actingAs($admin, 'admin')->getJson(route('admin.billing.tenants.open-invoices', $this->alpha))
        ->assertOk()
        ->assertJsonCount(1, 'invoices')
        ->assertJsonPath('invoices.0.id', $this->alphaInvoice->id);

    $response = $this->actingAs($admin, 'admin')->get(route('admin.tenants.show', $this->alpha))->assertOk();
    $billing = $response->viewData('page')['props']['billing'];
    $text = json_encode($billing, JSON_THROW_ON_ERROR);

    expect(collect($billing['invoices']['data'])->pluck('id')->all())->toBe([$this->alphaInvoice->id])
        ->and(collect($billing['payments']['data'])->pluck('company.id')->unique()->all())->toBe([$this->alpha->id])
        ->and(collect($billing['openInvoices'])->pluck('id')->all())->toBe([$this->alphaInvoice->id])
        ->and($billing['summary']['balance'])->toBe('£45.00')
        ->and($billing['settings']['recipients'])->toBe(['accounts@alpha.test'])
        ->and($text)->not->toContain($this->bravoInvoice->id)
        ->and($text)->not->toContain('INV-000002')
        ->and($text)->not->toContain('bravo')
        ->and($text)->not->toContain('Bravo');

    $response->assertInertia(fn (AssertableInertia $page) => $page->component('admin/tenants/show')->has('billing.invoices.data', 1));
});

test('the invoice filter by company only lists that company', function () {
    $this->actingAs($this->admin(AdminRole::Support), 'admin')
        ->get(route('admin.billing.invoices.index', ['company' => $this->bravo->id]))
        ->assertInertia(fn (AssertableInertia $page) => $page->has('invoices.data', 1)
            ->where('invoices.data.0.id', $this->bravoInvoice->id)
            ->where('totals.balance', '£19.00'));
});

test('the admin cash numbers add up every tenant', function () {
    $stats = BillingStats::for(CarbonImmutable::now());

    expect($stats['cashDue'])->toBe(['count' => 2, 'amount' => '64.00'])
        ->and($stats['collectedThisMonth'])->toBe(['count' => 2, 'amount' => '50.00']);
});
