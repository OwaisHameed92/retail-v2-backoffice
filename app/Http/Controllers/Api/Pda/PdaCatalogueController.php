<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\ChangeHandheldPrice;
use App\Domain\Handheld\Queries\HandheldProductList;
use App\Domain\Handheld\Queries\HandheldProductUnits;
use App\Domain\Handheld\Queries\HandheldSupplierList;
use App\Domain\TillData\Models\Product;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaBarcodeRequest;
use App\Http\Requests\Api\Pda\PdaListRequest;
use App\Http\Requests\Api\Pda\PdaPriceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handheld pick lists and the price change (docs/pda-api.md §2): products with the shop's price and stock, barcode
 * scans, a product's units, suppliers. Products and suppliers are the business's (company scope); another
 * business's id is "not found".
 */
class PdaCatalogueController extends Controller
{
    public function products(PdaListRequest $request, HandheldProductList $list): JsonResponse
    {
        $page = $list->handle(AuthenticateHandheldToken::shop($request), $request->text('search'), $request->text('barcode'), $request->perPage(), $request->pageNumber());

        return response()->json(['success' => true, 'data' => $page]);
    }

    public function resolveBarcode(PdaBarcodeRequest $request, HandheldProductUnits $units): JsonResponse
    {
        return response()->json(['success' => true, 'matches' => $units->forBarcode($request->barcode(), AuthenticateHandheldToken::shop($request))]);
    }

    public function units(Request $request, string $product, HandheldProductUnits $units): JsonResponse
    {
        $model = Product::query()->findOrFail($product);

        return response()->json(['success' => true, 'data' => $units->forProduct($model, AuthenticateHandheldToken::shop($request))]);
    }

    public function updatePrice(PdaPriceRequest $request, string $product, ChangeHandheldPrice $change): JsonResponse
    {
        $data = $change->handle(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::shop($request), Product::query()->findOrFail($product), $request->retail());

        return response()->json([
            'success' => true,
            'message' => $data['has_shop_price']
                ? 'Price updated — syncing to the tills. This shop has its own price, so it keeps selling at '.$data['shop_retail'].'.'
                : 'Price updated — syncing to the tills.',
            'data' => $data,
        ]);
    }

    public function suppliers(PdaListRequest $request, HandheldSupplierList $list): JsonResponse
    {
        return response()->json(['success' => true, 'data' => $list->handle($request->text('search'), $request->perPage(), $request->pageNumber())]);
    }
}
