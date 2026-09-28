<?php

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Billing\Actions\DeleteDraftInvoice;
use App\Domain\Billing\Actions\UpdateDraftInvoice;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\InvoiceMaths;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Money;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Billing\BillingTestHelpers;
use Tests\Feature\Licensing\LicensingTestHelpers;
use Tests\Feature\Tenants\TenantTestHelpers;

uses(TenantTestHelpers::class, LicensingTestHelpers::class, BillingTestHelpers::class);

beforeEach(function () {
    Mail::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-24 10:00:00', 'Europe/London'));
    $this->setVat(true);
});

test('£19.99 × 3 tills: VAT is rounded per line and the totals are the sums of the lines', function () {
    $company = $this->payingTenant(tills: 3);
    $this->standardPlan()->forceFill(['price_monthly' => '19.99'])->save();

    $invoice = $this->draftFor($company);

    // 19.99 × 20% = 3.998 → £4.00 per line; 3 × £4.00 = £12.00 (not 59.97 × 20% = £11.99).
    expect($invoice->lines->pluck('net')->all())->toBe(['19.99', '19.99', '19.99'])
        ->and($invoice->lines->pluck('vat')->all())->toBe(['4.00', '4.00', '4.00'])
        ->and($invoice->lines->pluck('gross')->all())->toBe(['23.99', '23.99', '23.99'])
        ->and($invoice->subtotal)->toBe('59.97')
        ->and($invoice->vat_total)->toBe('12.00')
        ->and($invoice->total)->toBe('71.97')
        ->and($invoice->balance)->toBe('71.97')
        ->and($invoice->vat_rate)->toBe('20.00');

    expect(Money::sum($invoice->lines->pluck('net')))->toBe($invoice->subtotal)
        ->and(Money::sum($invoice->lines->pluck('vat')))->toBe($invoice->vat_total)
        ->and(Money::sum($invoice->lines->pluck('gross')))->toBe($invoice->total);
});

test('money is stored as exact decimals, never floats', function () {
    $company = $this->payingTenant(tills: 3);
    $this->standardPlan()->forceFill(['price_monthly' => '0.10'])->save();

    $invoice = $this->draftFor($company);
    $row = DB::table('invoices')->where('id', $invoice->id)->first();

    // 0.1 + 0.2 style sums stay exact.
    expect($invoice->subtotal)->toBe('0.30')
        ->and($invoice->vat_total)->toBe('0.06')
        ->and($invoice->total)->toBe('0.36')
        ->and(Money::normalise($row->total))->toBe('0.36')
        ->and(Money::sum(array_fill(0, 100, '0.10')))->toBe('10.00')
        ->and(Money::sum(['0.10', '0.20']))->toBe('0.30');
});

test('a 17.5% VAT rate is applied exactly and shown as 17.5%', function () {
    config(['billing.vat.rate' => '17.5']);
    $company = $this->payingTenant(tills: 1);
    $this->standardPlan()->forceFill(['price_monthly' => '19.99'])->save();

    $invoice = $this->draftFor($company);

    // 19.99 × 17.5% = 3.49825 → £3.50
    expect($invoice->vat_rate)->toBe('17.50')
        ->and($invoice->vat_total)->toBe('3.50')
        ->and($invoice->total)->toBe('23.49')
        ->and(BillingFormat::percent($invoice->vat_rate))->toBe('17.5%');
});

test('line maths: fractional quantities and half-away-from-zero rounding', function (string $qty, string $price, string $rate, array $expected) {
    expect(InvoiceMaths::line($qty, $price, $rate))->toBe($expected);
})->with([
    'a third of a month' => ['0.3333', '25.00', '20.00', ['net' => '8.33', 'vat' => '1.67', 'gross' => '10.00']],
    'two thirds' => ['0.6667', '25.00', '20.00', ['net' => '16.67', 'vat' => '3.33', 'gross' => '20.00']],
    'half penny rounds up' => ['1', '0.03', '50.00', ['net' => '0.03', 'vat' => '0.02', 'gross' => '0.05']],
    'negative discount rounds away from zero' => ['1', '-0.03', '50.00', ['net' => '-0.03', 'vat' => '-0.02', 'gross' => '-0.05']],
    'no VAT' => ['3', '19.99', '0.00', ['net' => '59.97', 'vat' => '0.00', 'gross' => '59.97']],
    'large' => ['9999', '99999.99', '20.00', ['net' => '999899900.01', 'vat' => '199979980.00', 'gross' => '1199879880.01']],
]);

test('VAT-inclusive amounts split into net and VAT at the invoice rate', function (string $gross, string $rate, array $expected) {
    expect(InvoiceMaths::splitGross($gross, $rate))->toBe($expected);
})->with([
    ['12.00', '20.00', ['net' => '10.00', 'vat' => '2.00']],
    ['10.00', '20.00', ['net' => '8.33', 'vat' => '1.67']],
    ['0.01', '20.00', ['net' => '0.01', 'vat' => '0.00']],
    ['5.00', '0.00', ['net' => '5.00', 'vat' => '0.00']],
    ['23.49', '17.50', ['net' => '19.99', 'vat' => '3.50']],
    ['90', '20', ['net' => '75.00', 'vat' => '15.00']],
]);

test('money is formatted in pounds with thousands separators', function (mixed $amount, string $expected) {
    expect(BillingFormat::money($amount))->toBe($expected);
})->with([
    ['1234.56', '£1,234.56'],
    ['1234.5', '£1,234.50'],
    ['0', '£0.00'],
    [100, '£100.00'],
    ['-3', '-£3.00'],
    ['-1234.56', '-£1,234.56'],
    ['1234567.891', '£1,234,567.89'],
    ['999.995', '£1,000.00'],
    ['-0.004', '£0.00'],
]);

test('percent and quantity labels drop trailing zeros', function () {
    expect(BillingFormat::percent('20.00'))->toBe('20%')
        ->and(BillingFormat::percent('0.00'))->toBe('0%')
        ->and(BillingFormat::quantity('1.0000'))->toBe('1')
        ->and(BillingFormat::quantity('0.5000'))->toBe('0.5')
        ->and(BillingFormat::quantity('0.3333'))->toBe('0.3333');
});

test('with VAT switched off globally invoices carry no VAT and no seller VAT number', function () {
    config(['billing.vat.enabled' => false]);
    $company = $this->payingTenant(tills: 3);

    $invoice = $this->issuedFor($company);

    expect($invoice->vat_rate)->toBe('0.00')
        ->and($invoice->vat_total)->toBe('0.00')
        ->and($invoice->subtotal)->toBe('75.00')
        ->and($invoice->total)->toBe('75.00')
        ->and($invoice->seller_vat_number)->toBeNull()
        ->and($invoice->lines->pluck('vat')->unique()->all())->toBe(['0.00']);
});

test('with VAT on, the issued invoice freezes our VAT number', function () {
    $company = $this->payingTenant(tills: 1);

    $invoice = $this->issuedFor($company);
    config(['billing.vat.number' => 'GB999999999']);

    expect($this->fresh($invoice)->seller_vat_number)->toBe('GB123456789')
        ->and($invoice->total)->toBe('30.00');
});

test('a company billed without VAT gets 0% while others still pay VAT', function () {
    $noVat = $this->payingTenant('No Vat Ltd', 2, 'NVT');
    $withVat = $this->payingTenant('With Vat Ltd', 2, 'WVT');
    $this->setVat(true, false, $noVat);

    $a = $this->draftFor($noVat);
    $b = $this->draftFor($withVat);

    expect($a->vat_rate)->toBe('0.00')->and($a->vat_total)->toBe('0.00')->and($a->total)->toBe('50.00')
        ->and($b->vat_rate)->toBe('20.00')->and($b->vat_total)->toBe('10.00')->and($b->total)->toBe('60.00');
});

test('editing a draft recomputes lines and totals and keeps licences on kept lines', function () {
    $company = $this->payingTenant(tills: 2);
    $draft = $this->draftFor($company);
    [$keep, $drop] = $draft->lines->all();

    $updated = app(UpdateDraftInvoice::class)->handle($draft, '  Thanks for your business  ', [
        ['id' => $keep->id, 'description' => 'Till 1 (edited)', 'quantity' => '2', 'unit_price' => '19.99'],
        ['id' => null, 'description' => 'Loyalty discount', 'quantity' => '1', 'unit_price' => '-5.00'],
    ]);

    $lines = $updated->lines;

    expect($lines)->toHaveCount(2)
        ->and($lines[0]->id)->toBe($keep->id)
        ->and($lines[0]->licence_id)->toBe($keep->licence_id)
        ->and($lines[0]->quantity)->toBe('2.0000')
        ->and($lines[0]->net)->toBe('39.98')
        ->and($lines[0]->vat)->toBe('8.00')
        ->and($lines[1]->licence_id)->toBeNull()
        ->and($lines[1]->net)->toBe('-5.00')
        ->and($lines[1]->vat)->toBe('-1.00')
        ->and($lines[1]->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($updated->subtotal)->toBe('34.98')
        ->and($updated->vat_total)->toBe('7.00')
        ->and($updated->total)->toBe('41.98')
        ->and($updated->balance)->toBe('41.98')
        ->and($updated->notes)->toBe('Thanks for your business')
        ->and(InvoiceLine::withoutCompanyScope()->find($drop->id))->toBeNull()
        ->and(AuditLog::query()->where('action', 'invoice.updated')->where('subject_id', $draft->id)->exists())->toBeTrue();
});

test('a draft needs at least one line', function () {
    $draft = $this->draftFor($this->payingTenant(tills: 1));

    expect(fn () => app(UpdateDraftInvoice::class)->handle($draft, null, []))->toThrow(ValidationException::class, 'at least one line');
});

test('issued invoices are immutable: no edits and no deletes', function () {
    $company = $this->payingTenant(tills: 2);
    $invoice = $this->issuedFor($company);
    $line = $invoice->lines->first();

    expect(fn () => app(UpdateDraftInvoice::class)->handle($invoice, null, [
        ['id' => $line->id, 'description' => 'Cheaper', 'quantity' => '1', 'unit_price' => '1.00'],
    ]))->toThrow(ValidationException::class, 'INV-000001 is issued, so it cannot be changed');

    expect(fn () => app(DeleteDraftInvoice::class)->handle($invoice))->toThrow(ValidationException::class, 'Void it instead');

    $after = $this->fresh($invoice);

    expect($after->total)->toBe('60.00')
        ->and($after->lines)->toHaveCount(2)
        ->and($after->lines->first()->unit_price)->toBe('25.00');
});

test('the draft editor accepts pound signs and commas and rejects bad amounts', function () {
    $this->withoutVite();
    $admin = $this->admin(AdminRole::Accounts);
    $draft = $this->draftFor($this->payingTenant(tills: 1));
    $line = $draft->lines->first();

    $this->actingAs($admin, 'admin')->put(route('admin.billing.invoices.update', $draft->id), [
        'notes' => null,
        'lines' => [
            ['id' => $line->id, 'description' => 'Till 1', 'quantity' => '1', 'unit_price' => '£1,200.50'],
            ['description' => 'Install', 'quantity' => '0.5', 'unit_price' => '99.99'],
        ],
    ])->assertSessionHasNoErrors()->assertRedirect();

    // 1200.50 + 50.00 (49.995 → 50.00) = 1250.50 net; VAT 240.10 + 10.00.
    expect($this->fresh($draft)->total)->toBe('1500.60');

    $this->actingAs($admin, 'admin')->put(route('admin.billing.invoices.update', $draft->id), [
        'lines' => [
            ['description' => 'Zero', 'quantity' => '0', 'unit_price' => '10'],
            ['description' => 'Too precise', 'quantity' => '1', 'unit_price' => '19.999'],
        ],
    ])->assertSessionHasErrors(['lines.0.quantity', 'lines.1.unit_price']);

    expect($this->fresh($draft)->total)->toBe('1500.60')
        ->and(Invoice::withoutCompanyScope()->count())->toBe(1);
});
