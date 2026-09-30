<?php

namespace App\Domain\News\Queries;

use App\Domain\News\Queries\Lists\NewsDeliveryRows;
use App\Domain\News\Queries\Lists\NewsReturnRows;
use App\Domain\News\Queries\Lists\NewsVoucherRows;
use App\Domain\News\Queries\Lists\TitleRows;
use App\Domain\News\Support\NewsAccess;
use App\Domain\Purchasing\Data\PurchasingFilters;
use App\Domain\Purchasing\Queries\Lists\DocumentRows;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;

/**
 * Props for the newspaper lists (module 5.8): titles (the portal's), deliveries, returns and credits, vouchers (the
 * shops'), with tab counts, the kind's summary cards, filters and rows. A one-shop user sees only their shop (and the
 * titles for every shop).
 */
final class NewsPage
{
    public const KINDS = ['titles', 'deliveries', 'returns', 'vouchers'];

    /** @return DocumentRows<covariant Model> */
    public static function rows(string $kind): DocumentRows
    {
        return match ($kind) {
            'titles' => new TitleRows,
            'deliveries' => new NewsDeliveryRows,
            'returns' => new NewsReturnRows,
            'vouchers' => new NewsVoucherRows,
            default => abort(404),
        };
    }

    /** @return array<string, mixed> */
    public static function for(Request $request, string $kind): array
    {
        $rows = self::rows($kind);
        $filters = PurchasingFilters::from($request, $rows->statuses());

        return [
            'kind' => $kind,
            'tabs' => self::counts($filters->shop),
            'stats' => $rows->stats($rows->scoped($filters->shop)),
            'filters' => $filters->toArray(),
            'statuses' => $rows->statuses(),
            'rows' => $rows->page(TableQuery::from($request)->defaultPerPage(25), $filters),
            ...self::shared(),
        ];
    }

    /**
     * Shops, suppliers and what the user may do, shared by the news screens.
     *
     * @return array{shops: list<array{id: string, name: string, code: string}>, suppliers: list<array{id: string, name: string}>, oneShop: bool, can: array{manage: bool, everyShop: bool}}
     */
    public static function shared(): array
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        return [
            'shops' => Branch::query()->when($restricted !== null, fn ($q) => $q->whereKey($restricted))->orderBy('name')->get(['id', 'name', 'code'])
                ->map(fn (Branch $b) => ['id' => $b->id, 'name' => $b->name, 'code' => $b->code])->values()->all(),
            'suppliers' => Supplier::query()->orderBy('name')->get(['id', 'name'])
                ->map(fn (Supplier $s) => ['id' => $s->id, 'name' => (string) $s->name])->values()->all(),
            'oneShop' => $restricted !== null,
            'can' => ['manage' => NewsAccess::canManage(), 'everyShop' => NewsAccess::canManage() && $restricted === null],
        ];
    }

    /** @return array<string, int> */
    private static function counts(?string $shop): array
    {
        $counts = [];

        foreach (self::KINDS as $kind) {
            $counts[$kind] = self::rows($kind)->scoped($shop)->count();
        }

        return $counts;
    }
}
