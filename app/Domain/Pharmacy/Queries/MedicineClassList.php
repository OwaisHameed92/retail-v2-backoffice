<?php

namespace App\Domain\Pharmacy\Queries;

use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Enums\MedicineClassificationClass;
use App\Domain\TillData\Models\MedicineClassification;
use App\Domain\TillData\Models\Product;
use Illuminate\Http\Request;

/**
 * Medicine classes (module 5.10): which products are General Sale (GSL), Pharmacy-only (P) or Prescription-only
 * (POM). MedicineClassification is hub-owned (ownership.json), so the portal edits it and every till gets it in its
 * next pull (SaveMedicineClass). The list joins the product for its name and code, searchable by either; `class`
 * narrows it. `find` looks up products to classify (not yet classified first).
 */
final class MedicineClassList
{
    public const FIND_LIMIT = 20;

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $class = MedicineClassificationClass::tryFrom((string) $request->query('class'));
        $query = MedicineClassification::query()
            ->join('products', fn ($join) => $join->on('products.id', '=', 'medicine_classifications.product_id')
                ->on('products.company_id', '=', 'medicine_classifications.company_id'))
            ->select(['medicine_classifications.*', 'products.name as product_name', 'products.sku as product_sku'])
            ->when($class !== null, fn ($q) => $q->where('medicine_classifications.class', $class->value));

        $rows = TableQuery::from($request)->searchable(['products.name', 'products.sku'])
            ->sortable(['product_name'])->defaultSort('product_name')->defaultPerPage(25)
            ->paginate($query, fn (MedicineClassification $m) => [
                'id' => (string) $m->id,
                'productId' => (string) $m->product_id,
                'product' => (string) $m->getAttribute('product_name'),
                'sku' => (string) $m->getAttribute('product_sku') !== '' ? (string) $m->getAttribute('product_sku') : null,
                'class' => $m->class?->value,
                'note' => (string) $m->note !== '' ? (string) $m->note : null,
                'updatedAt' => $m->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            ]);

        $counts = MedicineClassification::query()->selectRaw('class, count(*) as n')->groupBy('class')->pluck('n', 'class');

        return [
            'rows' => $rows,
            'counts' => array_map(fn (MedicineClassificationClass $c) => ['class' => $c->value, 'count' => (int) ($counts[$c->value] ?? 0)], MedicineClassificationClass::cases()),
            'class' => $class?->value,
            'candidates' => self::find($request->query('find')),
        ];
    }

    /**
     * @return list<array{id: string, name: string, sku: string|null, class: string|null}>
     */
    private static function find(mixed $text): array
    {
        $text = is_string($text) ? trim($text) : '';

        if (mb_strlen($text) < 2) {
            return [];
        }

        $products = Product::query()
            ->where(fn ($q) => $q->where('name', 'like', '%'.$text.'%')->orWhere('sku', 'like', '%'.$text.'%'))
            ->orderBy('name')->limit(self::FIND_LIMIT)->get(['id', 'name', 'sku']);
        $classes = MedicineClassification::query()->whereIn('product_id', $products->pluck('id'))->get()->keyBy('product_id');

        return $products->map(fn (Product $p) => [
            'id' => (string) $p->id,
            'name' => (string) $p->name,
            'sku' => (string) $p->sku !== '' ? (string) $p->sku : null,
            'class' => $classes->get($p->id)?->class?->value,
        ])->sortBy(fn (array $p) => [$p['class'] !== null, $p['name']])->values()->all();
    }
}
