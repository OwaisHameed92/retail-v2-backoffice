<?php

namespace App\Domain\Accounts\Queries;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Accounts\Data\VatQuarter;
use App\Domain\Cash\Support\CashLookup;
use App\Domain\Reporting\Data\ReportScope;
use App\Domain\Reporting\Data\VatRateTotals;
use App\Domain\Reporting\Queries\VatReport;
use App\Domain\Reporting\Support\Units;
use App\Domain\Shared\Support\CsvText;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\SupplierCreditNote;
use App\Domain\TillData\Models\SupplierInvoice;
use App\Domain\TillData\Models\VatReturn;
use Illuminate\Database\Eloquent\Builder;

/**
 * The VAT return helper (module 5.5): boxes 1–9 for one quarter, worked out, never filed. Sales from the 4.8 VAT
 * report (`VatReport::byRate`, net of refunds, order deposits and charity left out); purchases from the shops'
 * supplier invoices (not drafts) less supplier credit notes, plus expenses (not voided). Expense VAT counts in box 4
 * only when the shop holds a VAT receipt. Boxes 2, 8 and 9 (goods to or from the EU, Northern Ireland only) are 0.
 * Boxes 6–9 are whole pounds (pence dropped), as HMRC asks. Making Tax Digital submission is a later item.
 */
final class VatReturnHelper
{
    /**
     * @return array<string, mixed>
     */
    public static function for(AccountsFilters $filters, VatQuarter $quarter): array
    {
        $f = $filters->between($quarter->from(), $quarter->to());
        $rates = app(VatReport::class)->byRate(ReportScope::tenant($f->from, $f->to, $f->branchIds()));
        $salesVat = Money::sum(array_map(fn (VatRateTotals $r) => $r->vat, $rates));
        $salesNet = Money::sum(array_map(fn (VatRateTotals $r) => $r->net, $rates));
        $invoices = self::sums(SupplierInvoice::query()->where(fn (Builder $q) => $q->where('status', '!=', 'draft')->orWhereNull('status')), 'invoice_date', $f);
        $credits = self::sums(SupplierCreditNote::query(), 'credit_date', $f);
        $expenses = ExpenseList::totals($f);

        $box1 = $salesVat;
        $box2 = '0.00';
        $box3 = Money::add($box1, $box2);
        $box4 = Money::add(Money::sub($invoices['vat'], $credits['vat']), $expenses['reclaimableVat']);
        $difference = Money::sub($box3, $box4);
        $box7 = Money::add(Money::sub($invoices['net'], $credits['net']), $expenses['net']);

        $boxes = [
            ['box' => 1, 'label' => 'VAT due on sales and other outputs', 'amount' => $box1],
            ['box' => 2, 'label' => 'VAT due on acquisitions from the EU (Northern Ireland only)', 'amount' => $box2],
            ['box' => 3, 'label' => 'Total VAT due (box 1 + box 2)', 'amount' => $box3],
            ['box' => 4, 'label' => 'VAT reclaimed on purchases and other inputs', 'amount' => $box4],
            ['box' => 5, 'label' => 'Net VAT to pay to HMRC or reclaim (difference between box 3 and box 4)', 'amount' => ltrim($difference, '-')],
            ['box' => 6, 'label' => 'Total value of sales and all other outputs excluding VAT', 'amount' => self::whole($salesNet)],
            ['box' => 7, 'label' => 'Total value of purchases and all other inputs excluding VAT', 'amount' => self::whole($box7)],
            ['box' => 8, 'label' => 'Total value of supplies of goods to the EU (Northern Ireland only)', 'amount' => '0'],
            ['box' => 9, 'label' => 'Total value of acquisitions of goods from the EU (Northern Ireland only)', 'amount' => '0'],
        ];

        return [
            'quarter' => ['value' => $quarter->key(), 'label' => $quarter->label(), 'from' => $quarter->from(), 'to' => $quarter->to(), 'stagger' => $quarter->stagger()],
            'quarters' => $quarter->options(),
            'boxes' => $boxes,
            'position' => Money::isNegative($difference) ? 'reclaim' : 'pay',
            'sources' => [
                ['key' => 'sales', 'label' => 'Till sales (net of refunds)', 'net' => $salesNet, 'vat' => $salesVat, 'count' => null, 'boxes' => '1, 6'],
                ['key' => 'invoices', 'label' => 'Supplier invoices', 'net' => $invoices['net'], 'vat' => $invoices['vat'], 'count' => $invoices['count'], 'boxes' => '4, 7'],
                ['key' => 'credits', 'label' => 'Supplier credit notes (taken off)', 'net' => Money::sub('0', $credits['net']), 'vat' => Money::sub('0', $credits['vat']), 'count' => $credits['count'], 'boxes' => '4, 7'],
                ['key' => 'expenses', 'label' => 'Expenses with a VAT receipt', 'net' => $expenses['net'], 'vat' => $expenses['reclaimableVat'], 'count' => $expenses['count'], 'boxes' => '4, 7'],
            ],
            'unreclaimedVat' => $expenses['unreclaimedVat'],
            'rates' => array_map(fn (VatRateTotals $r) => ['code' => $r->code !== '' ? $r->code : 'Unnamed rate', 'percentage' => $r->percentage, 'net' => $r->net, 'vat' => $r->vat, 'gross' => $r->gross], $rates),
            'tillReturns' => self::tillReturns($f),
        ];
    }

    /**
     * The CSV lines: a heading block, then one line per box.
     *
     * @param  array<string, mixed>  $vat  from for()
     * @return list<list<string>>
     */
    public static function csv(array $vat, string $business, string $shop): array
    {
        $rows = [
            ['VAT return helper (not filed)'],
            ['Business', CsvText::safe($business)],
            ['Shop', CsvText::safe($shop)],
            ['Period', $vat['quarter']['from'].' to '.$vat['quarter']['to']],
            [],
            ['Box', 'Description', 'Amount (£)'],
        ];

        foreach ($vat['boxes'] as $b) {
            $rows[] = [(string) $b['box'], $b['label'], $b['amount']];
        }

        $rows[] = [];
        $rows[] = ['Box 5 is VAT to '.($vat['position'] === 'reclaim' ? 'reclaim from' : 'pay to').' HMRC.'];
        $rows[] = ['Worked out from the till and purchase data in the portal. Check it before you file through MTD-compatible software.'];

        return $rows;
    }

    /**
     * @param  Builder<SupplierInvoice>|Builder<SupplierCreditNote>  $query
     * @return array{count: int, net: string, vat: string}
     */
    private static function sums(Builder $query, string $dateColumn, AccountsFilters $f): array
    {
        $row = $query->where($dateColumn, '>=', $f->from)->where($dateColumn, '<=', $f->to)
            ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
            ->toBase()->selectRaw('COUNT(*) as n, SUM(ROUND(COALESCE(net_amount, 0) * 100)) as net, SUM(ROUND(COALESCE(vat_amount, 0) * 100)) as vat')->first();

        return ['count' => (int) ($row->n ?? 0), 'net' => Units::decimal(Units::of($row->net ?? null), 2), 'vat' => Units::decimal(Units::of($row->vat ?? null), 2)];
    }

    /** Whole pounds, pence dropped (towards zero). */
    private static function whole(string $amount): string
    {
        return bcadd($amount, '0', 0);
    }

    /**
     * VAT returns the tills keep for these dates (read only), to compare.
     *
     * @return list<array<string, mixed>>
     */
    private static function tillReturns(AccountsFilters $f): array
    {
        $returns = VatReturn::query()->where('period_start', '<=', $f->to)->where('period_end', '>=', $f->from)
            ->when($f->shop !== null, fn (Builder $q) => $q->where('branch_id', $f->shop))
            ->orderByDesc('period_end')->limit(20)->get();
        $shops = CashLookup::shops($returns->pluck('branch_id'));

        return $returns->map(fn (VatReturn $r) => [
            'id' => $r->id,
            'shop' => CashLookup::name($shops, $r->branch_id),
            'from' => $r->period_start->format('Y-m-d'),
            'to' => $r->period_end->format('Y-m-d'),
            'scheme' => $r->scheme?->value,
            'boxes' => array_map(fn (int $i) => CashLookup::money($r->getAttribute('box'.$i)), range(1, 9)),
            'filedAt' => CashLookup::iso($r->filed_at),
        ])->values()->all();
    }
}
