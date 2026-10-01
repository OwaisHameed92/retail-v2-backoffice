<?php

namespace App\Domain\Purchasing\Invoices;

use App\Domain\Purchasing\Enums\InvoiceImportStatus;
use App\Domain\Purchasing\Models\InvoiceImport;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\SupplierInvoice;
use App\Domain\TillData\Models\VatRate;

/**
 * The deterministic checks of an invoice under review (module 6.5): every line's sum (quantity × price = line total),
 * the lines against the invoice's net, VAT and gross totals, VAT rates against the product's, quantities and costs
 * against the shop's order or delivery, low-confidence and missing product matches, and invoices already recorded.
 *
 * Levels: `error` blocks confirming (nothing to confirm, no invoice number), `warning` needs the user to tick "I have
 * checked these" (a sum that does not add up, a duplicate), `info` is for reading only. Also lists the cost price
 * changes the invoice implies (the preview for "update cost prices").
 */
final class InvoiceAnalysis
{
    /** Rounding allowed on a line (1p) and per line on the totals. */
    private const LINE_TOLERANCE = '0.01';

    /**
     * @param  array<string, mixed>  $draft
     * @return array{lines: list<array<string, mixed>>, totals: array{net: string, vat: string, gross: string}, issues: list<array{level: string, code: string, message: string, line: int|null}>, costChanges: list<array<string, mixed>>, reference: string|null}
     */
    public static function of(array $draft, ?string $importId = null): array
    {
        $issues = [];
        $lines = [];
        $productIds = array_values(array_filter(array_column($draft['lines'], 'productId')));
        $products = Product::query()->whereKey($productIds)->get(['id', 'name', 'sku', 'cost_price', 'vat_rate_id'])->keyBy('id');
        $vat = VatRate::query()->pluck('percentage', 'id')->map(fn ($p) => Money::normalise($p ?? 0, 2));
        $reference = InvoiceDocuments::reference($draft);
        [$net, $vatSum] = ['0', '0'];

        foreach ($draft['lines'] as $i => $line) {
            $n = $i + 1;
            $calc = $line['unitPrice'] !== null ? Money::mul($line['quantity'], $line['unitPrice']) : null;
            $lineNet = $line['lineNet'] ?? $calc ?? '0.00';
            $net = Money::add($net, $lineNet);
            $rate = $line['vatRate'];
            $product = $line['productId'] !== null ? $products->get($line['productId']) : null;
            $productRate = $product?->vat_rate_id !== null ? ($vat[$product->vat_rate_id] ?? null) : null;
            $vatSum = Money::add($vatSum, Money::mul($lineNet, bcdiv($rate ?? $productRate ?? '0', '100', 8)));
            $units = Money::mul($line['quantity'], $line['packSize'], 4);
            $costPerItem = $line['unitPrice'] !== null ? Money::normalise(bcdiv($line['unitPrice'], (string) $line['packSize'], 8), 4) : null;
            $ref = $line['productId'] !== null ? ($reference['lines'][$line['productId']] ?? null) : null;

            if ($line['unitPrice'] === null) {
                $issues[] = self::issue('warning', 'missingPrice', "Line {$n}: no price was read. Enter the price ex VAT.", $n);
            } elseif ($line['lineNet'] !== null && Money::compare(self::abs(Money::sub($calc, $line['lineNet'])), self::LINE_TOLERANCE) > 0) {
                $issues[] = self::issue('warning', 'lineTotal', "Line {$n}: {$line['quantity']} × £{$line['unitPrice']} is £{$calc}, but the line total says £{$line['lineNet']}.", $n);
            }

            if ($product === null) {
                $issues[] = self::issue('warning', 'unmatched', "Line {$n}: not matched to a product. Pick the product, or it is left out of any order and cost update.", $n);
            } elseif ($line['confidence'] < 80) {
                $issues[] = self::issue('info', 'lowConfidence', "Line {$n}: matched to {$product->name} by name only. Check it is the right product.", $n);
            }

            if ($product !== null && $rate !== null && $productRate !== null && Money::compare($rate, $productRate) !== 0) {
                $issues[] = self::issue('info', 'vatRate', "Line {$n}: VAT {$rate}% on the invoice, {$productRate}% on {$product->name}.", $n);
            }

            if ($ref !== null && Money::compare($ref['units'], $units) !== 0) {
                $what = $reference['source'] === 'delivery' ? 'received' : 'ordered';
                $issues[] = self::issue('warning', 'quantity', "Line {$n}: invoiced ".self::qty($units).' items, '.self::qty($ref['units'])." {$what}.", $n);
            }

            if ($ref !== null && $costPerItem !== null && Money::compare($ref['unitCost'], $costPerItem) !== 0) {
                $issues[] = self::issue('info', 'orderCost', "Line {$n}: £{$costPerItem} an item, £{$ref['unitCost']} on the ".($reference['source'] === 'delivery' ? 'delivery' : 'order').'.', $n);
            }

            $lines[] = [
                'calcNet' => $calc, 'units' => $units, 'costPerItem' => $costPerItem, 'vatRate' => $rate ?? $productRate,
                'product' => $product === null ? null : [
                    'id' => $product->id, 'name' => (string) $product->name, 'sku' => $product->sku ?: null,
                    'costPrice' => Money::normalise($product->cost_price ?? 0, 4), 'vatRate' => $productRate,
                ],
                'reference' => $ref,
            ];
        }

        $totals = ['net' => $net, 'vat' => $vatSum, 'gross' => Money::add($net, $vatSum)];
        $issues = [...self::header($draft, $totals, count($lines), $importId), ...$issues];

        return ['lines' => $lines, 'totals' => $totals, 'issues' => $issues, 'costChanges' => self::costChanges($draft, $lines), 'reference' => $reference['source']];
    }

    /**
     * @param  array<string, mixed>  $draft
     * @param  array{net: string, vat: string, gross: string}  $totals
     * @return list<array{level: string, code: string, message: string, line: int|null}>
     */
    private static function header(array $draft, array $totals, int $count, ?string $importId): array
    {
        $issues = [];
        $tolerance = Money::mul(self::LINE_TOLERANCE, max(2, $count));

        if ($count === 0) {
            $issues[] = self::issue('error', 'noLines', 'Add at least one line.');
        }

        if (($draft['invoiceNumber'] ?? null) === null) {
            $issues[] = self::issue('error', 'noNumber', 'Enter the invoice or delivery note number.');
        }

        if (($draft['supplierId'] ?? null) === null) {
            $issues[] = self::issue('warning', 'noSupplier', ($draft['supplierName'] ?? null) !== null
                ? "No supplier called {$draft['supplierName']}. Pick the supplier, or add them under Setup first."
                : 'Pick the supplier.');
        }

        foreach (['net' => 'netTotal', 'vat' => 'vatTotal', 'gross' => 'grossTotal'] as $key => $field) {
            $printed = $draft[$field] ?? null;

            if ($printed !== null && Money::compare(self::abs(Money::sub($printed, $totals[$key])), $tolerance) > 0) {
                $label = ['net' => 'net total', 'vat' => 'VAT', 'gross' => 'total'][$key];
                $issues[] = self::issue('warning', $key.'Total', "The lines add up to £{$totals[$key]} {$label}, but the invoice says £{$printed}.");
            }
        }

        if (($draft['netTotal'] ?? null) !== null && ($draft['vatTotal'] ?? null) !== null && ($draft['grossTotal'] ?? null) !== null
            && Money::compare(self::abs(Money::sub(Money::add($draft['netTotal'], $draft['vatTotal']), $draft['grossTotal'])), self::LINE_TOLERANCE) > 0) {
            $issues[] = self::issue('warning', 'invoiceSum', "On the invoice, £{$draft['netTotal']} + £{$draft['vatTotal']} VAT is not £{$draft['grossTotal']}.");
        }

        if (($draft['supplierId'] ?? null) !== null && ($draft['invoiceNumber'] ?? null) !== null) {
            $imported = InvoiceImport::query()->where('supplier_id', $draft['supplierId'])->where('invoice_number', $draft['invoiceNumber'])
                ->where('status', InvoiceImportStatus::Confirmed->value)->when($importId !== null, fn ($q) => $q->whereKeyNot($importId))->exists();
            $onTill = SupplierInvoice::query()->where('supplier_id', $draft['supplierId'])->where('invoice_number', $draft['invoiceNumber'])->exists();

            if ($imported || $onTill) {
                $issues[] = self::issue('warning', 'duplicate', "Invoice {$draft['invoiceNumber']} from this supplier is already ".($onTill ? 'on a till.' : 'imported.'));
            }
        }

        return $issues;
    }

    /**
     * Products whose cost per item on this invoice differs from their cost price (one per product, the first line).
     *
     * @param  array<string, mixed>  $draft
     * @param  list<array<string, mixed>>  $lines
     * @return list<array<string, mixed>>
     */
    private static function costChanges(array $draft, array $lines): array
    {
        $changes = [];

        foreach ($lines as $i => $line) {
            $product = $line['product'];

            if ($product === null || $line['costPerItem'] === null || isset($changes[$product['id']]) || Money::compare($line['costPerItem'], '0') <= 0
                || Money::compare($line['costPerItem'], $product['costPrice']) === 0) {
                continue;
            }

            $from = $product['costPrice'];
            $changes[$product['id']] = [
                'productId' => $product['id'], 'name' => $product['name'], 'sku' => $product['sku'], 'line' => $i + 1,
                'from' => $from, 'to' => $line['costPerItem'],
                'changePercent' => Money::compare($from, '0') > 0 ? Money::normalise(bcmul(bcdiv(bcsub($line['costPerItem'], $from, 8), $from, 8), '100', 8), 1) : null,
            ];
        }

        return array_values($changes);
    }

    /** @return array{level: string, code: string, message: string, line: int|null} */
    private static function issue(string $level, string $code, string $message, ?int $line = null): array
    {
        return ['level' => $level, 'code' => $code, 'message' => $message, 'line' => $line];
    }

    private static function abs(string $value): string
    {
        return ltrim($value, '-');
    }

    private static function qty(string $value): string
    {
        return rtrim(rtrim($value, '0'), '.');
    }
}
