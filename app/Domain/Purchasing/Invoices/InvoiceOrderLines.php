<?php

namespace App\Domain\Purchasing\Invoices;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\VatRate;

/**
 * Turns the matched lines of a confirmed invoice into head-office order lines (module 6.5, SaveHeadOfficeOrder input):
 * cases = the invoiced quantity, case size = the pack size, cost = the cost per item ex VAT, VAT rate = the business's
 * rate with the invoice's percentage, else the product's own. A product on several lines (or a part quantity, such as
 * weighed goods) becomes whole items: cases of the first line's size plus loose items. Unmatched lines are left out.
 */
final class InvoiceOrderLines
{
    /**
     * @param  array<string, mixed>  $draft
     * @param  array{lines: list<array<string, mixed>>}  $analysis
     * @return list<array{productId: string, orderedCases: int, caseQty: int, looseUnits: int, unitCost: string, vatRateId: string}>
     */
    public static function from(array $draft, array $analysis): array
    {
        $rates = VatRate::query()->get(['id', 'percentage'])->mapWithKeys(fn (VatRate $v) => [Money::normalise($v->percentage ?? 0, 2) => $v->id]);
        $productRates = Product::query()->whereKey(array_filter(array_column($draft['lines'], 'productId')))->pluck('vat_rate_id', 'id');
        $byProduct = [];

        foreach ($draft['lines'] as $i => $line) {
            $id = $line['productId'];
            $cost = $analysis['lines'][$i]['costPerItem'] ?? null;
            $rate = $analysis['lines'][$i]['vatRate'] ?? null;
            $vatRateId = ($rate !== null ? ($rates[$rate] ?? null) : null) ?? ($productRates[$id] ?? null);

            if ($id === null || $cost === null || $vatRateId === null) {
                continue;
            }

            $units = (int) Money::normalise(Money::mul($line['quantity'], $line['packSize'], 4), 0);
            $byProduct[$id] ??= ['productId' => $id, 'caseQty' => (int) $line['packSize'], 'units' => 0, 'unitCost' => $cost, 'vatRateId' => (string) $vatRateId];
            $byProduct[$id]['units'] += max(0, $units);
        }

        return array_values(array_map(fn (array $l) => [
            'productId' => $l['productId'], 'orderedCases' => intdiv($l['units'], $l['caseQty']), 'caseQty' => $l['caseQty'],
            'looseUnits' => $l['units'] % $l['caseQty'], 'unitCost' => $l['unitCost'], 'vatRateId' => $l['vatRateId'],
        ], array_filter($byProduct, fn (array $l) => $l['units'] > 0)));
    }
}
