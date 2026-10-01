<?php

namespace App\Domain\Demo\Builders;

use App\Domain\Demo\Catalogue\DemoPeople;
use App\Domain\Demo\Catalogue\DemoProducts;
use App\Domain\Demo\Support\DemoBusiness;
use App\Domain\Demo\Support\DemoPush;
use App\Domain\Demo\Support\DemoStaff;
use App\Domain\Reporting\Demo\DemoIds;
use App\Domain\Staff\Support\TillPinHasher;
use Illuminate\Support\Facades\DB;

/**
 * The set-up a till is configured with before it trades: payment types, reasons, the UK chart of accounts (each
 * shop's till has its own copy, as real tills do), suppliers with every product's supplier line and case size,
 * staff roles, staff (PINs in the till's own hash format) and the shops they work at, and wage rates.
 */
final class SetupBuilder
{
    /** Code => [name, type, VAT box, debit balance]. */
    public const ACCOUNTS = [
        '1001' => ['Stock', 'asset', null, true], '1200' => ['Bank current account', 'asset', null, true],
        '1210' => ['Cash in tills', 'asset', null, true], '1220' => ['Card receipts clearing', 'asset', null, true],
        '1230' => ['Cash office safe', 'asset', null, true], '2100' => ['Trade creditors', 'liability', null, false],
        '2200' => ['VAT on sales', 'liability', 1, false], '2201' => ['VAT on purchases', 'liability', 4, true],
        '2240' => ['Customer deposits held', 'liability', null, false], '2250' => ['Charity collections held', 'liability', null, false],
        '3000' => ['Capital', 'equity', null, false], '4000' => ['Sales standard rated', 'income', 6, false],
        '4010' => ['Sales reduced rate', 'income', 6, false], '4020' => ['Sales zero rated', 'income', 6, false],
        '5000' => ['Cost of sales', 'expense', null, true], '5010' => ['Stock losses and wastage', 'expense', null, true],
        '7500' => ['Shop expenses', 'expense', null, true], '7900' => ['Card and bank charges', 'expense', null, true],
        '8100' => ['Cash over and short', 'expense', null, true],
    ];

    /** Key => [type, text]. */
    public const REASONS = [
        'waste-date' => ['wastage', 'Out of date'], 'waste-damaged' => ['wastage', 'Damaged in store'],
        'adjust-count' => ['stockAdjust', 'Stock count correction'], 'adjust-found' => ['stockAdjust', 'Found in stock room'],
        'paidout-window' => ['paidOut', 'Window cleaner'], 'paidout-supplies' => ['paidOut', 'Shop supplies'],
        'paidout-milk' => ['paidOut', 'Milk and tea for staff room'], 'safedrop' => ['safeDrop', 'Safe drop'],
        'refund-faulty' => ['refund', 'Faulty or damaged'], 'refund-mind' => ['refund', 'Changed mind'],
        'void-walked' => ['void', 'Customer walked away'], 'age-noid' => ['ageRefusal', 'No ID shown'],
        'age-badid' => ['ageRefusal', 'ID not accepted'], 'age-proxy' => ['ageRefusal', 'Suspected proxy sale'],
        'recon-count' => ['cashReconciliation', 'Counting error'], 'discount-staff' => ['discount', 'Staff discount'],
        'nosale-change' => ['noSale', 'Change for customer'],
    ];

    public function handle(DemoBusiness $b, DemoPush $push): void
    {
        $main = $b->main();
        $at = $b->at($b->history + 30, 8, 30);

        foreach ([['cash', 'Cash', 1, 'isCash'], ['card', 'Card', 2, 'isCard'], ['account', 'Account', 3, 'isAccount'], ['voucher', 'Gift voucher', 4, 'isVoucher'], ['points', 'Loyalty points', 5, 'isPoints']] as [$key, $name, $position, $flag]) {
            $push->add($main, 'PaymentType', $b->id("tender|{$key}"), [
                'name' => $name, 'position' => $position, ...array_fill_keys(['isCash', 'isCard', 'isVoucher', 'isPoints', 'isAccount'], false),
                $flag => true, 'isDrsRefund' => false, 'opensDrawer' => $key === 'cash', 'showOnPayment' => true,
                'showOnRefund' => in_array($key, ['cash', 'card'], true), 'showOnCustomerPayment' => in_array($key, ['cash', 'card'], true), 'isActive' => true,
            ], $at);
        }

        $position = 0;

        foreach (self::REASONS as $key => [$type, $text]) {
            $push->add($main, 'Reason', $b->id("reason|{$key}"), [
                'type' => $type, 'text' => $text, 'position' => ++$position, 'isActive' => true,
                'accountCode' => match ($type) {
                    'paidOut' => '7500', 'wastage' => '5010', default => null
                },
            ], $at);
        }

        foreach ($b->shops as ['shop' => $shop]) {
            foreach (self::ACCOUNTS as $code => [$name, $type, $box, $debit]) {
                $push->add($shop, 'Account', $shop->id("account|{$code}"), [
                    'code' => (string) $code, 'name' => $name, 'type' => $type, 'parentCode' => null, 'vatBox' => $box,
                    'isSystem' => true, 'isActive' => true, 'isDebitBalance' => $debit,
                ], $at);
            }

            foreach (DemoStaff::WAGES as $i => [$from, $to, $rate, $label]) {
                $push->add($shop, 'WageRate', $shop->id("wage|{$i}"), [
                    'label' => $label, 'ageFrom' => $from, 'ageTo' => $to, 'effectiveFrom' => '2026-04-01', 'ratePerHour' => $rate / 100,
                    'isPlaceholder' => false,
                ], $at);
            }
        }

        $this->suppliers($b, $push);
        $this->staff($b, $push);
    }

    private function suppliers(DemoBusiness $b, DemoPush $push): void
    {
        $at = $b->at($b->history + 30, 9);

        foreach (DemoPeople::SUPPLIERS as $key => $s) {
            $push->add($b->main(), 'Supplier', $b->id("supplier|{$key}"), [
                'name' => $s[0], 'code' => $s[1], 'isActive' => true, 'contactName' => $s[2], 'phone' => $s[3],
                'email' => "orders@{$key}.example.co.uk", 'addressLine1' => 'Unit '.(3 + strlen($key)).' Trade Park', 'addressLine2' => null,
                'town' => $s[4], 'postcode' => $s[5], 'accountNumber' => strtoupper(substr($key, 0, 3)).str_pad((string) (abs(crc32($b->companyId.$key)) % 900000 + 100000), 6, '0'),
                'termsKind' => $s[6], 'paymentTermsDays' => $s[7], 'defaultLeadDays' => $s[8], 'minimumOrderValue' => $s[9] / 100,
                'orderMethod' => $s[10], 'deliveryDays' => $s[11], 'vatNumber' => 'GB'.(100000000 + abs(crc32($key)) % 800000000), 'notes' => $s[12],
            ], $at);
        }

        foreach (DemoProducts::all() as $key => $p) {
            $supplier = DemoPeople::supplierOf($p['category']);
            $this->productSupplier($b, $push, $key, $supplier, $p['cost'], (int) $p['case'], true);

            // Drinks and sweets are also on Booker's list, a little dearer: the second supplier on the product.
            if ($supplier === 'bestway' && abs(crc32($key)) % 3 === 0) {
                $this->productSupplier($b, $push, $key, 'booker', (int) round($p['cost'] * 1.04), (int) $p['case'], false);
            }
        }
    }

    private function productSupplier(DemoBusiness $b, DemoPush $push, string $key, string $supplier, int $cost, int $case, bool $preferred): void
    {
        $push->add($b->main(), 'ProductSupplier', $b->id("product-supplier|{$key}|{$supplier}"), [
            'productId' => $b->id("product|{$key}"), 'supplierId' => $b->id("supplier|{$supplier}"),
            'supplierSku' => str_pad((string) (abs(crc32("{$supplier}|{$key}")) % 1000000), 6, '0', STR_PAD_LEFT),
            'supplierPrice' => $cost / 100, 'caseQty' => $case, 'caseCost' => $cost * $case / 100, 'lastCost' => $cost / 100,
            'avgCost' => $cost / 100, 'lastPurchasedAt' => DemoBusiness::iso($b->at(7, 10)), 'leadDays' => DemoPeople::SUPPLIERS[$supplier][8],
            'isPreferred' => $preferred, 'isConsignment' => false, 'consignmentCost' => 0,
        ], $b->at($b->history + 30, 9));
    }

    private function staff(DemoBusiness $b, DemoPush $push): void
    {
        $at = $b->at($b->history + 30, 10);

        foreach (DemoStaff::ROLES as $key => [$name, $level]) {
            $push->add($b->main(), 'Role', $b->id("role|{$key}"), ['name' => $name, 'level' => $level, 'isSystem' => $key === 'owner', 'simpleModeDefault' => $key === 'cashier', 'permissions' => null], $at);
        }

        $links = [];

        foreach (DemoStaff::of($b) as $i => $m) {
            // The till's PIN format with a fixed salt, so a second run sends the same row.
            $pin = (string) (1000 + abs(crc32($m['id'])) % 9000);
            $push->add($b->main(), 'User', $m['id'], [
                'name' => $m['name'], 'pinHash' => TillPinHasher::hashWithSalt($pin, substr(hash('sha256', $m['id'], true), 0, 16)), 'rfid' => '',
                'roleId' => $b->id("role|{$m['role']}"), 'ratePerHour' => $m['rate'] / 100, 'maxShiftHours' => $m['kind'] === 'weekend' ? 8 : 10,
                'isServiceStaff' => false, 'allowCommission' => false, 'isPersonalLicenceHolder' => in_array($m['kind'], ['owner', 'manager'], true),
                'simpleModeOverride' => null, 'bigTextMode' => false, 'isActive' => $m['active'], 'preferredCulture' => 'en-GB',
            ], $at);

            foreach ($m['shops'] as $shop) {
                $links[] = [
                    'id' => DemoIds::fixed("demo|{$b->companyId}|staff-branch|{$m['id']}|{$shop->branchId}"), 'company_id' => $b->companyId,
                    'till_user_id' => $m['id'], 'branch_id' => $shop->branchId, 'created_at' => now(), 'updated_at' => now(),
                ];
            }
        }

        // Portal-only: which shop each person works at (module 4.5).
        DB::table('till_user_branches')->insertOrIgnore($links);
    }
}
