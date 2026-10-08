<?php

use App\Domain\Tenancy\Enums\CompanyRole;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\Feature\Cash\CashFixtures as C;
use Tests\Feature\Reporting\ReportFixtures as R;
use Tests\Feature\Reporting\ReportsHelpers as H;
use Tests\Feature\Shared\CountryModulesFixtures;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Pak POS pack 2026-10-07: phone wallets are PaymentType rows by name (JazzCash, Easypaisa, Raast QR; no tender enum,
 * no flags) and a wallet sale's SalePayment carries the typed transaction id in `providerRef`. On a Pakistan instance
 * (`country-pk`) the sale page, the payment type report and the shift page show them by name, and the sale page gives
 * the transaction id as its reference ("Ref …" on screen). Ledger account 1245 is till data like any other.
 */

const JAZZCASH_TYPE = '01K5T0Q8C4000000000000W001';
const EASYPAISA_TYPE = '01K5T0Q8C4000000000000W002';
const RAAST_TYPE = '01K5T0Q8C4000000000000W003';
const WALLET_SHIFT = '01K5T0Q8C40000000000SHFTW1';

beforeEach(function () {
    Cache::flush();
    CountryModulesFixtures::pakistan();
    $this->travelTo(CarbonImmutable::parse('2026-10-08 18:00', 'Asia/Karachi'));
    [$this->company, $this->leeds] = T::tenant();
    $id = $this->company->id;

    foreach ([JAZZCASH_TYPE => 'JazzCash', EASYPAISA_TYPE => 'Easypaisa', RAAST_TYPE => 'Raast QR'] as $typeId => $name) {
        DB::table('payment_types')->insert(['id' => $typeId, 'company_id' => $id, 'name' => $name, 'is_cash' => false, 'is_card' => false]);
    }

    // A sale paid by JazzCash with the transaction id the cashier typed (scheme, last 4, auth code, terminal id blank).
    $rows = array_map(function (array $row): array {
        if ($row['entity'] === 'SalePayment') {
            $row['payload'] = [...$row['payload'], 'paymentTypeId' => JAZZCASH_TYPE, 'paymentTypeName' => 'JazzCash', 'providerRef' => 'JC-7781240',
                'scheme' => '', 'last4' => '', 'authCode' => '', 'terminalTxnId' => '', 'currency' => 'PKR'];
        }

        return $row;
    }, R::basket('700001', '2026-10-08T07:00:00Z', 7000));
    R::push($this->company, $this->leeds, $rows);

    C::row('till_users', ['id' => 'USER0000000000000000000001', 'company_id' => $id, 'name' => 'Bilal']);
    C::shift($id, T::LEEDS, T::TILL_1, WALLET_SHIFT, ['opened_at' => '2026-10-08 03:00:00', 'closed_at' => '2026-10-08 12:00:00', 'status' => 'closed', 'closed_by' => 'USER0000000000000000000001'], ['4000.00', '4000.00', '0.00']);
    C::row('shift_tenders', ['id' => '01K5T0Q8C40000SHFTW1EASYPA', 'company_id' => $id, 'branch_id' => T::LEEDS, 'register_id' => T::TILL_1, 'shift_id' => WALLET_SHIFT,
        'payment_type_id' => EASYPAISA_TYPE, 'expected' => '1250.50', 'declared' => '1250.50', 'terminal_total' => '0.00', 'variance' => '0.00']);

    $this->owner = C::member($this->company, CompanyRole::Owner);
});

test('the sale page names the wallet and gives its transaction id as the reference', function () {
    $props = C::props($this->actingAs($this->owner)->get('/app/sales/'.R::saleId('700001')));

    expect($props['payments'])->toHaveCount(1)
        ->and($props['payments'][0])->toMatchArray(['name' => 'JazzCash', 'kind' => 'other', 'reference' => 'JC-7781240', 'scheme' => null, 'last4' => null, 'authCode' => null]);
})->group('country-pk');

test('the payment type report counts the wallet by its name', function () {
    $props = H::props($this->actingAs($this->owner)->get('/app/reports/tenders?period=custom&from=2026-10-08&to=2026-10-08'));
    $types = collect(H::table($props, 'types')['rows'])->keyBy('name');

    expect($types->keys()->all())->toContain('JazzCash')
        ->and($types['JazzCash']['payments'])->toBe(1);
})->group('country-pk');

test('the shift page lists the wallet tender by name, beside cash', function () {
    $props = C::props($this->actingAs($this->owner)->get('/app/cash/shifts/'.WALLET_SHIFT));
    $tenders = collect($props['tenders'])->keyBy('name');

    expect($tenders->keys()->all())->toBe(['Cash', 'Easypaisa'])
        ->and($tenders['Easypaisa'])->toMatchArray(['cash' => false, 'expected' => '1250.50', 'declared' => '1250.50']);
})->group('country-pk');
