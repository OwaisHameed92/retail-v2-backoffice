<?php

namespace App\Domain\Purchasing\Invoices;

use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\MasterCatalogue\Support\Gtin;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductBarcode;
use App\Domain\TillData\Models\ProductSupplier;
use App\Domain\TillData\Models\Supplier;

/**
 * Deterministic matching for an invoice under review (module 6.5), in the current company's scope. Nothing here asks
 * the model. Whatever the user picked (`supplierPinned`, a line's `pinned`, `documentPinned`) is kept as it is.
 *
 * - Supplier: VAT number, then the name (exact once "Ltd", "& Co" and punctuation are ignored), then the closest name.
 * - Lines: barcode (any UPC/EAN form) → the supplier's code or barcode → the product's own SKU → the closest name, with
 *   a confidence (100 barcode, 95 supplier code, 85 SKU, at most 89 for a name). An unknown barcode that is in the
 *   master catalogue gets its name, so the user can add the product.
 * - The shop's open order and delivery from that supplier: InvoiceDocuments.
 */
final class InvoiceMatcher
{
    public const NAME_THRESHOLD = 60;

    /**
     * @param  array<string, mixed>  $draft
     * @return array<string, mixed>
     */
    public function apply(array $draft, string $shopId): array
    {
        if (! ($draft['supplierPinned'] ?? false)) {
            $draft['supplierId'] = $this->supplier($draft['supplierVatNumber'] ?? null, $draft['supplierName'] ?? null);
        }

        $supplierId = $draft['supplierId'] ?? null;

        foreach ($draft['lines'] as $i => $line) {
            $draft['lines'][$i] = ($line['pinned'] ?? false) ? $this->keepPinned($line) : $this->line($line, $supplierId);
        }

        return InvoiceDocuments::apply($draft, $shopId);
    }

    public function supplier(?string $vatNumber, ?string $name): ?string
    {
        $suppliers = Supplier::query()->get(['id', 'name', 'vat_number', 'is_active']);
        $vat = self::vat($vatNumber);

        if ($vat !== '') {
            $hit = $suppliers->first(fn (Supplier $s) => self::vat($s->vat_number) === $vat);

            if ($hit !== null) {
                return $hit->id;
            }
        }

        $wanted = self::name($name);

        if ($wanted === '') {
            return null;
        }

        $best = null;
        $bestScore = 0.0;

        foreach ($suppliers as $supplier) {
            $candidate = self::name($supplier->name);

            if ($candidate === $wanted) {
                return $supplier->id;
            }

            similar_text($wanted, $candidate, $percent);
            $contains = $candidate !== '' && (str_contains($wanted, $candidate) || str_contains($candidate, $wanted));
            $score = max($percent, $contains ? 85.0 : 0.0);

            if ($score > $bestScore) {
                [$best, $bestScore] = [$supplier->id, $score];
            }
        }

        return $bestScore >= 75 ? $best : null;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function line(array $line, ?string $supplierId): array
    {
        $line = [...$line, 'productId' => null, 'matchedBy' => null, 'confidence' => 0, 'catalogueName' => null];
        $barcode = Gtin::normalise($line['barcode'] ?? null);
        $variants = $barcode === null ? [] : Gtin::variants($barcode);
        $code = $line['supplierCode'] ?? null;

        $hit = match (true) {
            $variants !== [] && ($id = ProductBarcode::query()->whereIn('barcode', $variants)->value('product_id')) !== null => [$id, 'barcode', 100],
            $variants !== [] && $supplierId !== null && ($id = ProductSupplier::query()->where('supplier_id', $supplierId)->whereIn('supplier_barcode', $variants)->value('product_id')) !== null => [$id, 'barcode', 100],
            $code !== null && $supplierId !== null && ($id = ProductSupplier::query()->where('supplier_id', $supplierId)->where('supplier_sku', $code)->value('product_id')) !== null => [$id, 'supplierCode', 95],
            $code !== null && ($id = Product::query()->where('sku', $code)->value('id')) !== null => [$id, 'sku', 85],
            default => $this->byName((string) ($line['description'] ?? '')),
        };

        if ($hit !== null && Product::query()->whereKey($hit[0])->exists()) {
            [$line['productId'], $line['matchedBy'], $line['confidence']] = $hit;
        } elseif ($variants !== []) {
            $line['catalogueName'] = MasterProduct::query()->whereIn('barcode', $variants)->whereNull('merged_into_id')->value('name');
        }

        return $line;
    }

    /**
     * @param  array<string, mixed>  $line
     * @return array<string, mixed>
     */
    private function keepPinned(array $line): array
    {
        $exists = $line['productId'] !== null && Product::query()->whereKey($line['productId'])->exists();

        return [...$line, 'productId' => $exists ? $line['productId'] : null, 'matchedBy' => $exists ? 'user' : null, 'confidence' => $exists ? 100 : 0];
    }

    /** @return array{0: string, 1: string, 2: int}|null */
    private function byName(string $description): ?array
    {
        $wanted = self::name($description);
        $words = array_values(array_filter(explode(' ', $wanted), fn (string $w) => mb_strlen($w) >= 3 && ! ctype_digit($w)));

        if ($words === []) {
            return null;
        }

        usort($words, fn (string $a, string $b) => mb_strlen($b) <=> mb_strlen($a));
        $candidates = Product::query()->whereNull('archived_at')
            ->where(function ($q) use ($words) {
                foreach (array_slice($words, 0, 3) as $word) {
                    $q->orWhere('name', 'like', '%'.addcslashes($word, '%_\\').'%');
                }
            })
            ->limit(60)->get(['id', 'name']);

        $best = null;
        $bestScore = 0.0;

        foreach ($candidates as $product) {
            similar_text($wanted, self::name((string) $product->name), $percent);

            if ($percent > $bestScore) {
                [$best, $bestScore] = [$product->id, $percent];
            }
        }

        return $best !== null && $bestScore >= self::NAME_THRESHOLD ? [$best, 'name', (int) min(89, round($bestScore * 0.9))] : null;
    }

    /** "Booker Ltd." and "BOOKER LIMITED" are the same name. */
    public static function name(?string $value): string
    {
        $text = mb_strtolower((string) $value);
        $text = (string) preg_replace('/[^\p{L}\p{N} ]+/u', ' ', $text);
        $text = (string) preg_replace('/\b(ltd|limited|plc|llp|co|company|uk|the|and)\b/u', ' ', $text);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }

    /** "GB 123 4567 89" and "123456789" are the same VAT number. */
    public static function vat(?string $value): string
    {
        $text = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', (string) $value));

        return str_starts_with($text, 'GB') ? substr($text, 2) : $text;
    }
}
