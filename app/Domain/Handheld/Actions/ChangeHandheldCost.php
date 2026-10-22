<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Catalogue\Actions\SaveProduct;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\PdaErrors;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\Product;
use Illuminate\Validation\ValidationException;

/**
 * `PUT /api/pda/products/{id}/cost {cost}`: the product's cost price per base unit, ex VAT (`Product.costPrice`, 4
 * dp), through SaveProduct, the portal product form's path: hub-owned, so every till pulls it; audited
 * `product.updated` naming the handheld user. Unit costs are not changed.
 *
 * Only for a handheld user allowed to change costs (`can_change_cost`, 403 COST_CHANGE_NOT_ALLOWED); an archived
 * product → 422 PRODUCT_ARCHIVED. The format is the product form's (up to 8 whole digits, 4 decimals; the request).
 */
final class ChangeHandheldCost
{
    public function __construct(private readonly SaveProduct $save) {}

    /**
     * @return array<string, mixed> the reply's `data`
     *
     * @throws ApiException|ValidationException
     */
    public function handle(HandheldUser $user, Product $product, string $cost): array
    {
        if (! $user->can_change_cost) {
            throw PdaErrors::costChangeNotAllowed();
        }

        if (! $product->is_active) {
            throw PdaErrors::productArchived('cost');
        }

        $previous = Money::normalise($product->cost_price, 4);

        try {
            $saved = $this->save->handle($product, ['cost_price' => Money::normalise($cost, 4)]);
        } catch (ValidationException $e) {
            $errors = $e->errors();

            throw ValidationException::withMessages(isset($errors['cost_price'])
                ? ['cost' => $errors['cost_price']]
                : ['product' => 'Fix this product on the portal first: '.(collect($errors)->flatten()->first() ?? 'its details are not valid.')]);
        }

        $product = $saved->product->refresh();

        return [
            'id' => $product->id,
            'product_name' => $product->name,
            'previous_cost' => $previous,
            'cost' => Money::normalise($product->cost_price, 4),
            'changed' => $saved->changed !== [],
            'is_synced' => false,
        ];
    }
}
