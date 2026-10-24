<?php

namespace App\Http\Controllers\Api;

use App\Domain\TillImages\Actions\DeleteTillProductImage;
use App\Domain\TillImages\Actions\StoreTillProductImage;
use App\Domain\TillImages\Queries\TillImageList;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSyncKey;
use App\Http\Support\TillImageBody;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Till product pictures (IMG-1, contract proposal docs/contracts/proposals/product-images): `GET sync/product-images`,
 * `PUT` and `DELETE sync/product-images/{productId}`. Auth, contract header, rate limits and the switch are the route
 * group's middleware; the work is in TillImageList, StoreTillProductImage and DeleteTillProductImage.
 */
class ProductImageController extends Controller
{
    public function index(Request $request, TillImageList $list): JsonResponse
    {
        return response()->json($list->handle(AuthenticateSyncKey::caller($request)->company, $request->query('after'), $request->query('max')));
    }

    public function update(Request $request, string $productId, StoreTillProductImage $store): JsonResponse
    {
        return response()->json($store->handle(AuthenticateSyncKey::caller($request)->company, TillImageBody::read($request, $productId)));
    }

    public function destroy(Request $request, string $productId, DeleteTillProductImage $delete): JsonResponse
    {
        return response()->json($delete->handle(AuthenticateSyncKey::caller($request)->company, TillImageBody::productId($productId)));
    }
}
