<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\Catalogue\Queries\CatalogueOptions;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\TillData\Enums\AgeRule;

/** One master catalogue product as the admin and tenant screens show it. */
final class MasterRow
{
    /**
     * @return array<string, mixed>
     */
    public static function of(MasterProduct $p): array
    {
        return [
            'id' => $p->id,
            'barcode' => $p->barcode,
            'name' => $p->name,
            'brand' => $p->brand,
            'size' => $p->size()->label(),
            'department' => $p->department,
            'category' => $p->category,
            'vatRate' => $p->vat_rate === null ? null : CatalogueOptions::percent((string) $p->vat_rate),
            'rrp' => $p->rrp === null ? null : (string) $p->rrp,
            'ageRule' => $p->age_rule,
            'ageLabel' => $p->isAgeRestricted() ? CatalogueOptions::ageRule(AgeRule::tryFrom($p->age_rule)) : null,
            'imageUrl' => $p->image_url,
            'inStarterPacks' => $p->in_starter_packs,
            'source' => $p->source->value,
            'sourceLabel' => $p->source->label(),
            'sourceRef' => $p->source_ref,
            'updatedAt' => $p->updated_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * Distinct catalogue departments with their product counts (current rows), A to Z.
     *
     * @return list<array{value: string, label: string, count: int}>
     */
    public static function departments(bool $starterOnly = false): array
    {
        return MasterProduct::query()->current()->whereNotNull('department')
            ->when($starterOnly, fn ($q) => $q->where('in_starter_packs', true))
            ->selectRaw('department, count(*) as aggregate')->groupBy('department')->orderBy('department')->get()
            ->map(fn ($row) => ['value' => (string) $row->getAttribute('department'), 'label' => (string) $row->getAttribute('department'), 'count' => (int) $row->getAttribute('aggregate')])
            ->values()->all();
    }
}
