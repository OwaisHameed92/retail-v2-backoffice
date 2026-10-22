<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Support\HandheldOrderGuard;
use App\Domain\Purchasing\Actions\SaveHeadOfficeOrder;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\UnitType;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\PurchaseOrder;
use Illuminate\Validation\ValidationException;

/**
 * `POST /api/pda/purchase-orders` and `PUT …/{id}`: a head-office order for the handheld user's shop, through the
 * portal's own head-office order action (SaveHeadOfficeOrder → DraftHeadOfficeOrder): numbering (HO-LDS-000001),
 * totals, VAT from each product's VAT rate, the audit (naming the handheld user) and the pull to that shop only.
 *
 * App line → order line: `quantity_ordered` (whole units of the line's unit) → cases, `conversion_factor` (base units
 * per unit, default 1) → case size (a decimal quantity of a kg product → loose kg, see line()), cost per base unit = `base_unit_cost`, else `unit_cost` ÷ conversion (4 dp), else
 * the product's cost price. `status` draft (default on create: the shop sees it but cannot receive it yet) or sent
 * (placed: receivable on the till); an edit keeps the order's status unless `status` is sent. Editing is refused
 * once the shop's till owns the order (409, HandheldOrderGuard).
 */
final class SaveHandheldPurchaseOrder
{
    public function __construct(private readonly SaveHeadOfficeOrder $save) {}

    /**
     * @param  array<string, mixed>  $input  supplier_id, expected_delivery_date, notes, status, items[] {product_id, quantity_ordered, unit_cost?, conversion_factor?, base_unit_cost?}
     *
     * @throws ApiException|ValidationException
     */
    public function handle(Branch $shop, array $input, ?PurchaseOrder $order = null): PurchaseOrder
    {
        if ($order !== null) {
            HandheldOrderGuard::assertPortalOwns($order);
        }

        $items = array_values((array) ($input['items'] ?? []));
        $products = Product::query()->whereKey(array_column($items, 'product_id'))->get()->keyBy('id');
        $lines = [];

        foreach ($items as $i => $item) {
            $product = $products[$item['product_id'] ?? ''] ?? null;

            if (! $product instanceof Product) {
                throw ValidationException::withMessages(["items.{$i}.product_id" => 'Line '.($i + 1).': choose one of this business\'s products.']);
            }

            $lines[] = self::line($i, $product, $item);
        }

        $status = $input['status'] ?? null;

        try {
            return $this->save->handle($shop, [
                'supplierId' => (string) ($input['supplier_id'] ?? ''),
                'status' => in_array($status, ['draft', 'sent'], true) ? $status : ($order->status->value ?? 'draft'),
                'expectedDate' => $input['expected_delivery_date'] ?? null,
                'notes' => $input['notes'] ?? null,
                'lines' => $lines,
            ], $order);
        } catch (ValidationException $e) {
            throw HandheldOrderGuard::rename($e);
        }
    }

    /**
     * One order line. A whole quantity is cases of `conversion_factor` base units (as phase 1). A decimal quantity (up
     * to 3 dp) is only for a weighed (kg) product in its own unit: `orderedCases` 0, case size 1 and the quantity as
     * loose units, as the till saves its own orders (contract: `orderedUnits` is a decimal).
     *
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private static function line(int $i, Product $product, array $item): array
    {
        $quantity = Money::normalise($item['quantity_ordered'], 4);
        $conversion = max(1, (int) ($item['conversion_factor'] ?? 1));
        $cost = fn (int $per) => self::baseCost($item, $per, (string) $product->cost_price);

        if (Money::compare($quantity, bcadd($quantity, '0', 0)) === 0) {
            return ['productId' => $product->id, 'orderedCases' => (int) $quantity, 'caseQty' => $conversion, 'looseUnits' => 0,
                'unitCost' => $cost($conversion), 'vatRateId' => $product->vat_rate_id];
        }

        if ($product->unit_type !== UnitType::Kg) {
            throw ValidationException::withMessages(["items.{$i}.quantity_ordered" => 'Line '.($i + 1).': order whole units. Only weighed (kg) products take decimals.']);
        }

        if ($conversion !== 1) {
            throw ValidationException::withMessages(["items.{$i}.conversion_factor" => 'Line '.($i + 1).': a weighed quantity is in the product\'s own unit (kg). Leave conversion_factor at 1.']);
        }

        return ['productId' => $product->id, 'orderedCases' => 0, 'caseQty' => 1, 'looseUnits' => $quantity,
            'unitCost' => $cost(1), 'vatRateId' => $product->vat_rate_id];
    }

    /**
     * @param  array<string, mixed>  $item
     */
    private static function baseCost(array $item, int $conversion, string $productCost): string
    {
        if (isset($item['base_unit_cost']) && $item['base_unit_cost'] !== '') {
            return Money::normalise($item['base_unit_cost'], 4);
        }

        if (isset($item['unit_cost']) && $item['unit_cost'] !== '') {
            return Money::round(bcdiv(Money::normalise($item['unit_cost'], 4), (string) $conversion, 8), 4);
        }

        return Money::normalise($productCost, 4);
    }
}
