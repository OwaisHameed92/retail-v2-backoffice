<?php

use App\Domain\Billing\Actions\GenerateInvoice;
use App\Domain\Billing\Data\NewInvoice;
use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\InvoiceLine;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Actions\DeactivateRegister;
use Carbon\CarbonImmutable;
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

test('an invoice gets one line per live licence of an active till, for the next period', function () {
    $company = $this->payingTenant(tills: 3);

    $invoice = $this->draftFor($company);

    expect($invoice->status)->toBe(InvoiceStatus::Draft)
        ->and($invoice->number)->toBeNull()
        ->and($invoice->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($invoice->period_end->format('Y-m-d'))->toBe('2026-11-30')
        ->and($invoice->lines)->toHaveCount(3)
        ->and($invoice->lines->pluck('description')->all())->toBe([
            'Standard plan · Till 1, Leeds · 1 Nov – 30 Nov 2026',
            'Standard plan · Till 2, Leeds · 1 Nov – 30 Nov 2026',
            'Standard plan · Till 3, Leeds · 1 Nov – 30 Nov 2026',
        ])
        ->and($invoice->lines->every(fn (InvoiceLine $line) => $line->licence_id !== null))->toBeTrue()
        ->and($invoice->lines->first()->quantity)->toBe('1.0000')
        ->and($invoice->lines->first()->unit_price)->toBe('25.00')
        ->and($invoice->subtotal)->toBe('75.00')
        ->and($invoice->vat_total)->toBe('15.00')
        ->and($invoice->total)->toBe('90.00')
        ->and($invoice->balance)->toBe('90.00');

    expect(AuditLog::query()->where('action', 'invoice.created')->where('subject_id', $invoice->id)->exists())->toBeTrue();
});

test('deactivated tills and revoked licences are not invoiced', function () {
    $company = $this->payingTenant(tills: 3);
    $till = $this->registerOf($this->branchOf($company), '03');
    app(DeactivateRegister::class)->handle($till);

    expect($this->draftFor($company)->lines)->toHaveCount(2);
});

test('yearly billing uses the yearly price and a one-year period', function () {
    $company = $this->payingTenant(tills: 1);

    $invoice = $this->draftFor($company, new NewInvoice(cycle: BillingCycle::Yearly));

    expect($invoice->period_start->format('Y-m-d'))->toBe('2026-11-01')
        ->and($invoice->period_end->format('Y-m-d'))->toBe('2027-10-31')
        ->and($invoice->lines->first()->unit_price)->toBe('250.00')
        ->and($invoice->total)->toBe('300.00');
});

test('a period already invoiced is refused unless an extra invoice is confirmed', function () {
    $company = $this->payingTenant(tills: 1);
    $this->issuedFor($company);

    expect(fn () => $this->draftFor($company))->toThrow(ValidationException::class);

    $extra = $this->draftFor($company, new NewInvoice(allowOverlap: true));
    expect($extra->status)->toBe(InvoiceStatus::Draft);
});

test('a company without tills to bill gets a clear error', function () {
    $company = $this->payingTenant(tills: 1);
    app(DeactivateRegister::class)->handle($this->registerOf($this->branchOf($company), '01'));

    expect(fn () => $this->draftFor($company))->toThrow(ValidationException::class, 'has no active tills with a licence to invoice');
});

test('proration is off by default and charges only the uncovered days when on', function () {
    $company = $this->payingTenant(tills: 2);
    [$first, $second] = $this->licencesOf($company);
    // Till 2 is paid until 10 Nov: 20 of the 30 November days are left to charge.
    $second->forceFill(['expires_at' => CarbonImmutable::parse($this->londonEnd('2026-11-10'))])->save();
    $first->forceFill(['expires_at' => CarbonImmutable::parse($this->londonEnd('2026-10-31'))])->save();

    $plain = app(GenerateInvoice::class)->plan($company, new NewInvoice(periodStart: CarbonImmutable::parse('2026-11-01')), CarbonImmutable::now());
    expect($plain->prorate)->toBeFalse()->and($plain->totals['subtotal'])->toBe('50.00');

    $prorated = app(GenerateInvoice::class)->plan($company, new NewInvoice(periodStart: CarbonImmutable::parse('2026-11-01'), prorate: true), CarbonImmutable::now());
    $line = collect($prorated->lines)->firstWhere('licence_id', $second->id);

    expect($line['quantity'])->toBe('0.6667')
        ->and($line['net'])->toBe('16.67')
        ->and($line['description'])->toEndWith('(20 of 30 days)')
        ->and($prorated->totals['subtotal'])->toBe('41.67');
});

test('the period follows the paid expiry, or starts today for lapsed tills', function () {
    $company = $this->payingTenant(tills: 1, paidUntil: '2026-10-10');

    $invoice = $this->draftFor($company);

    expect($invoice->period_start->format('Y-m-d'))->toBe('2026-10-24')
        ->and($invoice->period_end->format('Y-m-d'))->toBe('2026-11-23');
});

test('period ends never overflow short months', function (string $start, string $cycle, string $end) {
    expect(BillingCycle::from($cycle)->periodEnd(CarbonImmutable::parse($start))->format('Y-m-d'))->toBe($end);
})->with([
    ['2026-11-01', 'monthly', '2026-11-30'],
    ['2027-03-01', 'monthly', '2027-03-31'],
    ['2027-01-31', 'monthly', '2027-02-28'],
    ['2028-01-31', 'monthly', '2028-02-29'],
    ['2026-10-15', 'monthly', '2026-11-14'],
    ['2026-11-01', 'yearly', '2027-10-31'],
    ['2028-02-29', 'yearly', '2029-02-28'],
]);
