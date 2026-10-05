<?php

namespace App\Domain\MasterCatalogue\Queries;

use App\Domain\MasterCatalogue\Enums\ContributionStatus;
use App\Domain\MasterCatalogue\Enums\MasterSource;
use App\Domain\MasterCatalogue\Models\CatalogueContribution;
use App\Domain\MasterCatalogue\Models\MasterProduct;
use App\Domain\Shared\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * The admin master catalogue list (/admin/catalogue): search (name, brand, barcode), filters (source, department,
 * "possible duplicates": products sharing a name), the counts on the cards and tabs.
 */
final class MasterCatalogueList
{
    public const SORTABLE = ['name', 'rrp', 'updated_at'];

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $table = TableQuery::from($request)->sortable(self::SORTABLE)->defaultSort('name');
        $source = MasterSource::tryFrom((string) $request->query('source'));
        $department = is_string($request->query('department')) && $request->query('department') !== '' ? mb_substr((string) $request->query('department'), 0, 120) : null;
        $duplicates = $request->boolean('duplicates');
        $search = $table->search();

        $query = MasterProduct::query()->current()
            ->when($source !== null, fn (Builder $q) => $q->where('source', $source->value))
            ->when($department !== null, fn (Builder $q) => $q->where('department', $department))
            // Through a derived table: MySQL rewrites a grouped IN subquery into a semi-join and then refuses its HAVING
            // under only_full_group_by (SQLite does not).
            ->when($duplicates, fn (Builder $q) => $q->whereIn(DB::raw('lower(name)'), DB::query()->fromSub(self::duplicateNames(), 'd')->select('dup_name')))
            ->when($search !== null, fn (Builder $q) => CatalogueSearch::search($q, (string) $search));

        $bySource = MasterProduct::query()->current()->selectRaw('source, count(*) as aggregate')->groupBy('source')->pluck('aggregate', 'source')->all();

        return [
            'products' => $table->paginate($query, fn (MasterProduct $p) => MasterRow::of($p)),
            'filters' => ['source' => $source?->value, 'department' => $department, 'duplicates' => $duplicates],
            'sources' => MasterSource::options(),
            'departments' => MasterRow::departments(),
            'counts' => [
                'total' => (int) array_sum($bySource),
                'bySource' => array_map('intval', $bySource),
                'pending' => CatalogueContribution::query()->where('status', ContributionStatus::Pending)->count(),
                'duplicates' => DB::query()->fromSub(self::duplicateNames(), 'd')->count(),
            ],
        ];
    }

    /**
     * Lower-case names more than one current product has.
     *
     * @return Builder<MasterProduct>
     */
    public static function duplicateNames(): Builder
    {
        return MasterProduct::query()->current()->selectRaw('lower(name) as dup_name')->groupByRaw('lower(name)')->havingRaw('count(*) > 1');
    }
}
