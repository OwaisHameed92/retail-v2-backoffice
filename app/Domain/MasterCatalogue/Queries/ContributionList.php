<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\MasterCatalogue\Enums\ContributionStatus;
use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\Shared\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The admin review queue (/admin/catalogue/contributions): barcodes tills sold that the catalogue did not know, the
 * most seen first. Nothing here identifies a business: rows hold the barcode, name, size and a count only.
 */
final class ContributionList
{
    public const SORTABLE = ['seen_count', 'name', 'created_at'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $table = TableQuery::from($request)->sortable(self::SORTABLE)->defaultSort('seen_count', 'desc');
        $status = ContributionStatus::tryFrom((string) $request->query('status')) ?? ContributionStatus::Pending;
        $search = $table->search();

        $query = CatalogueContribution::query()->where('status', $status)
            ->when($search !== null, fn (Builder $q) => $q->where(fn (Builder $w) => $w->where('barcode', 'like', $search.'%')->orWhere('name', 'like', '%'.$search.'%')));

        $counts = CatalogueContribution::query()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status')->all();

        return [
            'contributions' => $table->paginate($query, fn (CatalogueContribution $c) => [
                'id' => $c->id,
                'barcode' => $c->barcode,
                'name' => $c->name,
                'size' => $c->size()->label(),
                'sizeValue' => $c->size_value === null ? '' : rtrim(rtrim((string) $c->size_value, '0'), '.'),
                'sizeUnit' => $c->size_unit ?? '',
                'packQty' => $c->pack_qty === null ? '' : (string) $c->pack_qty,
                'seen' => $c->seen_count,
                'status' => $c->status->value,
                'statusLabel' => $c->status->label(),
                'firstSeenAt' => $c->created_at?->toIso8601ZuluString(),
                'lastSeenAt' => $c->updated_at?->toIso8601ZuluString(),
                'reviewedAt' => $c->reviewed_at?->toIso8601ZuluString(),
                'masterProductId' => $c->master_product_id,
            ]),
            'filters' => ['status' => $status->value],
            'counts' => array_map(fn (ContributionStatus $s) => ['value' => $s->value, 'label' => $s->label(), 'count' => (int) ($counts[$s->value] ?? 0)], ContributionStatus::cases()),
            'options' => MasterProductForm::options(),
        ];
    }
}
