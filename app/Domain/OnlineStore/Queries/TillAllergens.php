<?php

namespace App\Domain\OnlineStore\Queries;

use Illuminate\Support\Facades\DB;

/**
 * The allergen information the tills already hold for products (OS-8, audit OSA-3), read only: the allergen matrix
 * (`product_allergen_matrices.allergens_csv` and `notes`; the shop's own row wins over the every-shop row) and the
 * scale label text (`scale_plu_items.allergen_text`). Used when the business has not typed its own "Ingredients &
 * allergens" text for the online store.
 *
 *     app(TillAllergens::class)->for($companyId, $branchId, [$productId]); // [$productId => 'Contains: Milk, Gluten.']
 */
final class TillAllergens
{
    /**
     * @param  list<string>  $productIds
     * @return array<string, string>
     */
    public function for(string $companyId, ?string $branchId, array $productIds): array
    {
        if ($productIds === []) {
            return [];
        }

        $out = [];
        $rows = DB::table('product_allergen_matrices')->where('company_id', $companyId)->whereIn('product_id', $productIds)
            ->whereNull('deleted_at')->where(fn ($q) => $q->whereNull('branch_id')->when($branchId !== null, fn ($w) => $w->orWhere('branch_id', $branchId)))
            ->orderByRaw('branch_id IS NULL DESC')->orderBy('id')->get(['product_id', 'allergens_csv', 'notes']);

        foreach ($rows as $row) {
            $text = self::matrixText((string) $row->allergens_csv, (string) $row->notes);

            if ($text !== null) {
                $out[(string) $row->product_id] = $text;
            }
        }

        $missing = array_values(array_diff($productIds, array_keys($out)));

        if ($missing !== []) {
            $labels = DB::table('scale_plu_items')->where('company_id', $companyId)->whereIn('product_id', $missing)->whereNull('deleted_at')
                ->whereNotNull('allergen_text')->where('allergen_text', '!=', '')
                ->when($branchId !== null, fn ($q) => $q->where(fn ($w) => $w->whereNull('branch_id')->orWhere('branch_id', $branchId)))
                ->orderBy('id')->pluck('allergen_text', 'product_id');

            foreach ($labels as $productId => $text) {
                $out[(string) $productId] ??= trim((string) $text);
            }
        }

        return $out;
    }

    /** "Contains: Milk, Gluten. Made in a kitchen that handles nuts." or null when the row says nothing. */
    public static function matrixText(string $csv, string $notes): ?string
    {
        $names = array_values(array_unique(array_filter(array_map(
            fn (string $a) => ucfirst(strtolower(trim((string) preg_replace('/(?<!^)([A-Z])/', ' $1', trim($a))))),
            preg_split('/[,;|]+/', $csv) ?: [],
        ))));
        $notes = trim($notes);
        $parts = array_filter([$names === [] ? null : 'Contains: '.implode(', ', $names).'.', $notes === '' ? null : $notes]);

        return $parts === [] ? null : implode(' ', $parts);
    }
}
