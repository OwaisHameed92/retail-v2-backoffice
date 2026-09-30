<?php

use App\Domain\Accounts\Data\VatQuarter;
use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Accounts\AccountsFixtures as A;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\TillData\TillFixtures;

/*
 * Module 5.5: the VAT return helper's boxes (sales from the 4.8 VAT data, purchases from supplier invoices, credit
 * notes and expenses), its CSV, and the expenses list.
 */

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00', 'Europe/London'));
    [$this->company] = TillFixtures::tenant();
    $id = $this->company->id;
    A::salesData($id);
    $row = fn (string $table, string $key, array $values) => DB::table($table)->insert(['id' => A::id($key, count(DB::table($table)->get()) + 1), 'company_id' => $id, 'branch_id' => TillFixtures::LEEDS, ...$values]);
    $row('supplier_invoices', 'SI', ['invoice_date' => '2026-09-05', 'status' => 'approved', 'net_amount' => '200.00', 'vat_amount' => '40.00', 'gross_amount' => '240.00']);
    $row('supplier_invoices', 'SI', ['invoice_date' => '2026-09-06', 'status' => 'draft', 'net_amount' => '999.00', 'vat_amount' => '199.80', 'gross_amount' => '1198.80']);
    $row('supplier_invoices', 'SI', ['invoice_date' => '2026-06-30', 'status' => 'paid', 'net_amount' => '500.00', 'vat_amount' => '100.00', 'gross_amount' => '600.00']);
    $row('supplier_credit_notes', 'SC', ['credit_date' => '2026-09-06', 'net_amount' => '20.00', 'vat_amount' => '4.00', 'gross_amount' => '24.00']);
    $row('expenses', 'EX', ['expense_date' => '2026-09-07', 'payee_name' => 'Window cleaner', 'net' => '50.00', 'vat' => '10.00', 'gross' => '60.00', 'vat_receipt_held' => true, 'is_voided' => false]);
    $row('expenses', 'EX', ['expense_date' => '2026-09-08', 'payee_name' => 'Market stall', 'net' => '25.60', 'vat' => '5.12', 'gross' => '30.72', 'vat_receipt_held' => false, 'is_voided' => false]);
    $row('expenses', 'EX', ['expense_date' => '2026-09-09', 'payee_name' => 'Mistake', 'net' => '100.00', 'vat' => '20.00', 'gross' => '120.00', 'vat_receipt_held' => true, 'is_voided' => true]);
    $this->owner = C::member($this->company, CompanyRole::Owner);
});

test('boxes 1 to 9 add up from sales, purchases, credit notes and expenses with a VAT receipt', function () {
    $vat = C::props($this->actingAs($this->owner)->get('/app/accounts/vat?quarter=2026-07&shop=all'));
    $boxes = collect($vat['boxes'])->pluck('amount', 'box')->all();

    expect($boxes)->toBe([1 => '27.00', 2 => '0.00', 3 => '27.00', 4 => '46.00', 5 => '19.00', 6 => '135', 7 => '255', 8 => '0', 9 => '0'])
        ->and($vat['position'])->toBe('reclaim')
        ->and($vat['unreclaimedVat'])->toBe('5.12')
        ->and($vat['quarter'])->toMatchArray(['from' => '2026-07-01', 'to' => '2026-09-30', 'stagger' => 1])
        ->and(collect($vat['sources'])->pluck('vat', 'key')->all())->toBe(['sales' => '27.00', 'invoices' => '40.00', 'credits' => '-4.00', 'expenses' => '10.00']);
});

test('box 5 is VAT to pay when sales VAT is more than purchase VAT, and a one-shop filter narrows every source', function () {
    $vat = C::props($this->actingAs($this->owner)->get('/app/accounts/vat?quarter=2026-07&shop='.TillFixtures::BRADFORD));
    $boxes = collect($vat['boxes'])->pluck('amount', 'box')->all();

    expect($boxes[1])->toBe('10.00')->and($boxes[4])->toBe('0.00')->and($boxes[5])->toBe('10.00')->and($boxes[6])->toBe('50')
        ->and($vat['position'])->toBe('pay');
});

test('the default quarter is the last one ended, and each stagger lists its own quarters', function () {
    expect(VatQuarter::fromQuery(null)->key())->toBe('2026-07')
        ->and(VatQuarter::fromQuery(null, '2')->key())->toBe('2026-05')
        ->and(VatQuarter::fromQuery(null, '3')->key())->toBe('2026-06')
        ->and(VatQuarter::fromQuery('2026-08')->to())->toBe('2026-10-31')
        ->and(VatQuarter::fromQuery('nonsense')->key())->toBe('2026-07')
        ->and(array_column(VatQuarter::fromQuery('2026-07')->options(), 'value'))->toContain('2026-10', '2026-07', '2026-04');
});

test('the VAT return downloads as CSV and prints', function () {
    $csv = $this->actingAs($this->owner)->get('/app/accounts/vat/csv?quarter=2026-07&shop=all');
    $csv->assertOk()->assertDownload('vat-return-2026-07.csv');
    $body = $csv->streamedContent();

    expect($body)->toContain('Kirkgate Convenience')
        ->toContain('"Net VAT to pay to HMRC or reclaim (difference between box 3 and box 4)",19.00')
        ->toContain('Box 5 is VAT to reclaim from HMRC.');

    $this->actingAs($this->owner)->get('/app/accounts/vat/print?quarter=2026-07')->assertInertia(fn ($page) => $page
        ->component('app/accounts/vat-print')
        ->where('business', 'Kirkgate Convenience')
        ->has('boxes', 9));
});

test('expenses are listed read only; voided ones are shown but left out of the totals', function () {
    $props = C::props($this->actingAs($this->owner)->get('/app/accounts/expenses?from=2026-09-01&to=2026-09-30&shop=all'));

    expect($props['expenses']['data'])->toHaveCount(3)
        ->and(collect($props['expenses']['data'])->firstWhere('payee', 'Mistake')['voided'])->toBeTrue()
        ->and($props['totals'])->toBe(['count' => 2, 'net' => '75.60', 'vat' => '15.12', 'gross' => '90.72', 'reclaimableVat' => '10.00', 'unreclaimedVat' => '5.12']);
});
