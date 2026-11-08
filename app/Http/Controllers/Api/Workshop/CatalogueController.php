<?php

namespace App\Http\Controllers\Api\Workshop;

use App\Domain\Workshop\Queries\ConnectorInventory;
use App\Domain\Workshop\Queries\ConnectorProducts;
use App\Domain\Workshop\Queries\ConnectorReference;
use App\Domain\Workshop\Support\ConnectorException;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * BikeBook connector reads (WORKSHOP-BIKEBOOK.md §3.1–§3.4): stores, VAT rates, products, inventory. The connection
 * and its business come from AuthenticateWorkshopConnector; every query runs inside that business.
 */
class CatalogueController extends Controller
{
    public function stores(Request $request, ConnectorReference $reference): JsonResponse
    {
        return response()->json($reference->stores(ConnectorPaging::connection($request)));
    }

    public function taxRates(ConnectorReference $reference): JsonResponse
    {
        return response()->json($reference->taxRates());
    }

    public function products(Request $request, ConnectorProducts $products): JsonResponse
    {
        $paging = ConnectorPaging::from($request);
        [$items, $total] = $products->page(ConnectorPaging::connection($request), $paging['since'], $paging['page'], $paging['size'], $paging['light']);

        return ConnectorPaging::reply($request, $items, $total, $paging);
    }

    public function product(Request $request, string $id, ConnectorProducts $products): JsonResponse
    {
        $model = $products->one(ConnectorPaging::connection($request), $id);

        return $model === null ? throw ConnectorException::notFound('No active product with this id in this shop\'s business.') : response()->json($model);
    }

    /** `?variant=` (one id, or several comma separated) or `?lastUpdated=` (paged). */
    public function inventory(Request $request, ConnectorInventory $inventory): JsonResponse
    {
        $connection = ConnectorPaging::connection($request);
        $variant = $request->query('variant');

        if (is_string($variant) && trim($variant) !== '') {
            $ids = array_values(array_filter(array_map('trim', explode(',', $variant))));

            if (count($ids) > (int) config('workshop.max_page_size', 500)) {
                throw ConnectorException::invalid('Ask for at most 500 variants at once.');
            }

            return response()->json($inventory->forVariants($connection, $ids));
        }

        $paging = ConnectorPaging::from($request);

        if ($paging['since'] === null) {
            throw ConnectorException::invalid('Send variant (one or more ids) or lastUpdated. For many variants use POST /api/Inventory/batch.');
        }

        [$items, $total] = $inventory->changedSince($connection, $paging['since'], $paging['page'], $paging['size']);

        return ConnectorPaging::reply($request, $items, $total, $paging);
    }

    /** `POST /api/Inventory/batch` `{variants: [ids…]}`, at most 500. */
    public function inventoryBatch(Request $request, ConnectorInventory $inventory): JsonResponse
    {
        $variants = $request->json('variants');

        if (! is_array($variants) || ! array_is_list($variants) || $variants === []) {
            throw ConnectorException::invalid('variants must be a list of variant ids.');
        }

        if (count($variants) > (int) config('workshop.max_page_size', 500)) {
            throw ConnectorException::invalid('Ask for at most 500 variants at once.');
        }

        return response()->json($inventory->forVariants(ConnectorPaging::connection($request), array_values(array_map('strval', array_filter($variants, 'is_scalar')))));
    }
}
