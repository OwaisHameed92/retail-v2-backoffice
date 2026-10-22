<?php

namespace App\Http\Controllers\Api\Pda;

use App\Domain\Handheld\Actions\ChangeHandheldCost;
use App\Domain\Handheld\Actions\SetHandheldShopPrice;
use App\Domain\Handheld\Actions\SetHandheldUnitPrice;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductUnit;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateHandheldToken;
use App\Http\Requests\Api\Pda\PdaCostRequest;
use App\Http\Requests\Api\Pda\PdaShopPriceRequest;
use App\Http\Requests\Api\Pda\PdaUnitPriceRequest;
use Illuminate\Http\JsonResponse;

/**
 * Handheld phase 2 product changes (docs/pda-api.md §2.1–2.3): this shop's own price, a unit's price (business or
 * this shop) and the cost, each through the portal's own path. Products are the business's (company scope); another
 * business's product, or a unit of another product, is "not found".
 */
class PdaProductChangeController extends Controller
{
    public function shopPrice(PdaShopPriceRequest $request, string $product, SetHandheldShopPrice $set): JsonResponse
    {
        $data = $set->handle(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::shop($request), Product::query()->findOrFail($product), $request->retail());

        return response()->json([
            'success' => true,
            'message' => match (true) {
                ! $data['changed'] => 'No change — this shop already sells at '.$data['shop_retail'].'.',
                $data['has_shop_price'] => 'Shop price saved — this shop’s till gets it at its next sync.',
                default => 'Shop price removed — this shop sells at the business price ('.$data['business_retail'].') from its next sync.',
            },
            'type' => 'shop_price',
            'data' => $data,
        ]);
    }

    public function unitPrice(PdaUnitPriceRequest $request, string $product, string $unit, SetHandheldUnitPrice $set): JsonResponse
    {
        $model = Product::query()->findOrFail($product);
        $data = $set->handle(AuthenticateHandheldToken::user($request), AuthenticateHandheldToken::shop($request), $model,
            ProductUnit::query()->where('product_id', $model->id)->findOrFail($unit), $request->retailPrice(), $request->scope());

        return response()->json([
            'success' => true,
            'message' => match (true) {
                ! $data['changed'] => 'No change to this unit’s price.',
                $data['scope'] === 'shop' && $data['has_shop_price'] => 'Unit price saved for this shop — its till gets it at its next sync.',
                $data['scope'] === 'shop' => 'This shop’s own unit price removed — it sells at the business price from its next sync.',
                default => 'Unit price updated — syncing to the tills.',
            },
            'type' => 'unit_price',
            'data' => $data,
        ]);
    }

    public function cost(PdaCostRequest $request, string $product, ChangeHandheldCost $change): JsonResponse
    {
        $data = $change->handle(AuthenticateHandheldToken::user($request), Product::query()->findOrFail($product), $request->cost());

        return response()->json([
            'success' => true,
            'message' => $data['changed'] ? 'Cost updated — syncing to the tills.' : 'No change — the cost is already '.$data['cost'].'.',
            'type' => 'product_cost',
            'data' => $data,
        ]);
    }
}
