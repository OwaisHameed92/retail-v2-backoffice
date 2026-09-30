<?php

use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Reporting\ReportFixtures as R;
use Tests\Feature\TillData\TillFixtures as T;

/*
 * Module 4.6: the receipt page shows exactly the stored rows — lines with staff / promotion discounts, VAT rows,
 * payments (cash with change, loyalty points), the linked refund, the refund approval and a line voided while the
 * basket was open (matched by till and time).
 */

const RECEIPT_CUSTOMER = 'CUST00000000000000000000A1';

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-09-25 12:00', 'Europe/London'));
    [$this->company, $this->leeds] = T::tenant();
    $company = $this->company->id;
    DB::table('customers')->insert(['id' => RECEIPT_CUSTOMER, 'company_id' => $company, 'name' => 'Aisha Rahman', 'card_no' => 'LC000123']);
    DB::table('till_users')->insert(['id' => R::USER_1, 'company_id' => $company, 'name' => 'Sam Carter']);
    DB::table('till_users')->insert(['id' => R::USER_2, 'company_id' => $company, 'name' => 'Priya Shah']);

    $edit = function (array $row): array {
        $p = &$row['payload'];
        if ($row['entity'] === 'Sale') {
            [$p['customerId'], $p['discountTotal'], $p['promoTotal']] = [RECEIPT_CUSTOMER, 0.5, 0.3];
        }
        if ($row['entityId'] === '01K5VB0000000SN2R001400001') {
            [$p['lineDiscount'], $p['discountSource'], $p['promotionName'], $p['promotionDiscount'], $p['lineTotal']] = [0.5, 'staff', 'Two for £3', 0.3, 3.2];
        }
        if ($row['entity'] === 'SalePayment') {
            [$p['paymentTypeName'], $p['amount'], $p['changeGiven'], $p['appliedAmount'], $p['scheme'], $p['last4']] = ['Cash', 5.0, 1.85, 3.15, '', ''];
        }
        unset($p);

        return $row;
    };

    $prev = R::basket('400000', '2026-09-24T09:30:00Z', 3990);
    $rows = array_map($edit, R::basket('400001', '2026-09-24T10:00:00Z', 4000));
    $points = collect($rows)->firstWhere('entity', 'SalePayment');
    $points['entityId'] = $points['payload']['id'] = '01K5VB00000000SPX001400001';
    $points['key'] = 'SalePayment:01K5VB00000000SPX001400001:1';
    $points['seq'] = 4100;
    $points['payload'] = [...$points['payload'], 'paymentTypeName' => 'Loyalty points', 'paymentTypeId' => '01K5T0Q8C4000000000000T007', 'amount' => 2.0,
        'changeGiven' => 0, 'appliedAmount' => 2.0, 'providerRef' => RECEIPT_CUSTOMER, 'position' => 2, 'terminalTxnId' => '', 'authCode' => ''];
    $refund = R::basket('400002', '2026-09-24T11:00:00Z', 4200, ['type' => 'refund', 'original' => R::saleId('400001'), 'user' => R::USER_2]);
    R::push($this->company, $this->leeds, [...$prev, ...$rows, $points, ...$refund]);
    DB::table('sales')->where('id', R::saleId('400000'))->update(['number' => 10]);
    DB::table('sales')->where('id', R::saleId('400001'))->update(['number' => 11]);

    $audit = fn (string $id, string $action, string $entity, string $at, array $after, string $user = R::USER_1) => DB::table('till_audit_logs')->insert([
        'id' => $id, 'company_id' => $company, 'branch_id' => T::LEEDS, 'register_id' => T::TILL_1, 'user_id' => $user,
        'entity_name' => 'Sale', 'entity_id' => $entity, 'action' => $action, 'before_json' => '', 'after_json' => json_encode($after), 'at' => $at, 'reason' => '',
    ]);
    $audit('AUD0000000000000000000001', 'LineVoided', 'CART00000000000000000000001', '2026-09-24 09:55:00', ['product' => 'Coca-Cola 500ml', 'quantity' => 1, 'value' => 1.85, 'pin' => '1234']);
    $audit('AUD0000000000000000000002', 'LineVoided', 'CART00000000000000000000000', '2026-09-24 09:20:00', ['product' => 'Before the previous sale']);
    $audit('AUD0000000000000000000003', 'RefundApproved', R::saleId('400002'), '2026-09-24 11:00:00', ['receiptNumber' => 'LDS-01-400002'], R::USER_2);

    $this->owner = User::factory()->create();
    $this->company->users()->attach($this->owner->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);
});

test('every receipt figure is the stored row: lines, discounts, VAT, payments, change and totals', function () {
    $id = R::saleId('400001');
    $props = $this->actingAs($this->owner)->get("/app/sales/{$id}")->assertOk()->viewData('page')['props'];
    $sale = DB::table('sales')->where('id', $id)->first();
    $lines = DB::table('sale_lines')->where('sale_id', $id)->orderBy('position')->get();
    $vats = DB::table('sale_vats')->where('sale_id', $id)->orderBy('percentage')->get();

    expect($props['sale'])->toMatchArray([
        'receiptNumber' => 'LDS-01-400001', 'type' => 'sale', 'status' => 'completed', 'shop' => 'Leeds', 'till' => 'Till 1',
        'staff' => 'Sam Carter', 'customer' => ['id' => RECEIPT_CUSTOMER, 'name' => 'Aisha Rahman', 'cardNo' => 'LC000123'], 'day' => '2026-09-24',
    ])->and($props['totals'])->toMatchArray([
        'subtotal' => Money::normalise($sale->subtotal), 'discount' => '0.50', 'promo' => '0.30', 'vat' => Money::normalise($sale->vat_total),
        'total' => Money::normalise($sale->total), 'net' => Money::sub($sale->total, $sale->vat_total), 'tendered' => '7.00', 'change' => '1.85',
    ]);

    expect($props['lines'])->toHaveCount($lines->count());
    foreach ($lines as $i => $line) {
        expect($props['lines'][$i])->toMatchArray([
            'id' => $line->id, 'name' => $line->name, 'qty' => Money::normalise($line->qty, 4), 'unitPrice' => Money::normalise($line->unit_price),
            'lineDiscount' => Money::normalise($line->line_discount), 'promotionDiscount' => Money::normalise($line->promotion_discount),
            'vatAmount' => Money::normalise($line->vat_amount), 'lineTotal' => Money::normalise($line->line_total),
        ]);
    }
    expect($props['lines'][1])->toMatchArray(['discountSource' => 'staff', 'promotionName' => 'Two for £3', 'lineDiscount' => '0.50', 'ownDiscount' => '0.20', 'lineTotal' => '3.20']);

    expect(collect($props['vat'])->map(fn ($v) => [$v['net'], $v['vat'], $v['gross']])->all())
        ->toBe($vats->map(fn ($v) => [Money::normalise($v->net), Money::normalise($v->vat), Money::normalise($v->gross)])->all());

    expect($props['payments'])->toHaveCount(2)
        ->and($props['payments'][0])->toMatchArray(['name' => 'Cash', 'amount' => '5.00', 'change' => '1.85'])
        ->and($props['payments'][1])->toMatchArray(['name' => 'Loyalty points', 'kind' => 'points', 'amount' => '2.00', 'reference' => null,
            'pointsCustomer' => ['id' => RECEIPT_CUSTOMER, 'name' => 'Aisha Rahman', 'cardNo' => 'LC000123']]);
});

test('linked refunds both ways, the refund approval and lines voided while the basket was open', function () {
    $this->actingAs($this->owner)->get('/app/sales/'.R::saleId('400001'))->assertInertia(fn (Assert $page) => $page
        ->component('app/sales/show')
        ->where('original', null)
        ->has('linked', 1)->where('linked.0.receiptNumber', 'LDS-01-400002')->where('linked.0.type', 'refund')->where('linked.0.total', '-3.70')
        ->has('events', 1)->where('events.0.action', 'LineVoided')->where('events.0.matchedByTime', true)->where('events.0.user', 'Sam Carter')
        ->where('events.0.details', [['label' => 'Product', 'value' => 'Coca-Cola 500ml'], ['label' => 'Quantity', 'value' => '1'], ['label' => 'Value', 'value' => '1.85']]));

    $this->actingAs($this->owner)->get('/app/sales/'.R::saleId('400002'))->assertInertia(fn (Assert $page) => $page
        ->where('sale.type', 'refund')->where('sale.staff', 'Priya Shah')->where('totals.total', '-3.70')
        ->where('original.receiptNumber', 'LDS-01-400001')->has('linked', 0)
        ->where('events.0.action', 'RefundApproved')->where('events.0.matchedByTime', false)->where('events.0.user', 'Priya Shah'));
});

test('another business gets 404 for the receipt', function () {
    $other = Company::factory()->create();
    $user = User::factory()->create();
    $other->users()->attach($user->id, ['role' => CompanyRole::Owner->value, 'is_active' => true]);

    $this->actingAs($user)->get('/app/sales/'.R::saleId('400001'))->assertNotFound();
    $this->actingAs($this->owner)->get('/app/sales/NOTASALE00000000000000000')->assertNotFound();
});
