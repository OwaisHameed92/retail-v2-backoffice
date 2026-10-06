<?php

namespace App\Domain\Purchasing\Reorder;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Country\MoneyFormat;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductSupplier;
use App\Domain\TillData\Models\Supplier;
use Illuminate\Validation\ValidationException;

/**
 * Turns chosen suggestion lines (shop, supplier, product, cases) into one draft order per shop and supplier (module
 * 6.4), read again from the database in the current business: open shops only, the supplier's case size and case
 * cost (else the product's cost price, case of 1), VAT rate from the product. Used for the preview and the save.
 *
 * @phpstan-type PlanLine array{productId: string, name: string, cases: int, caseQty: int, units: int, unitCost: string, vatRateId: string, cost: string}
 * @phpstan-type PlanOrder array{shop: Branch, supplier: Supplier, lines: list<PlanLine>, net: string}
 */
final class ReorderOrderPlan
{
    public const MAX_LINES = 500;

    /**
     * @param  list<array{shopId: string, supplierId: string, productId: string, cases: int}>  $lines
     * @return list<PlanOrder>
     *
     * @throws ValidationException
     */
    public static function build(array $lines): array
    {
        $lines = array_values(array_filter($lines, fn (array $l) => (int) $l['cases'] > 0));

        if ($lines === []) {
            throw ValidationException::withMessages(['lines' => 'Choose at least one product with a quantity to order.']);
        }

        if (count($lines) > self::MAX_LINES) {
            throw ValidationException::withMessages(['lines' => 'That is more than '.self::MAX_LINES.' lines. Order in smaller groups.']);
        }

        $shops = Branch::query()->whereKey(array_unique(array_column($lines, 'shopId')))->get()->keyBy('id');
        $suppliers = Supplier::query()->whereKey(array_unique(array_column($lines, 'supplierId')))->get()->keyBy('id');
        $products = Product::query()->whereKey(array_unique(array_column($lines, 'productId')))->get()->keyBy('id');
        $links = ProductSupplier::query()->whereIn('supplier_id', $suppliers->keys())->whereIn('product_id', $products->keys())->get()
            ->keyBy(fn (ProductSupplier $l) => $l->supplier_id.'|'.$l->product_id);
        $orders = [];

        foreach ($lines as $line) {
            $shop = $shops->get($line['shopId']) ?? throw ValidationException::withMessages(['lines' => 'A shop on the order was not found in this business.']);
            $supplier = $suppliers->get($line['supplierId']) ?? throw ValidationException::withMessages(['lines' => 'A supplier on the order was not found in this business.']);
            $product = $products->get($line['productId']) ?? throw ValidationException::withMessages(['lines' => 'A product on the order was not found in this business.']);

            if (! $shop->is_active) {
                throw ValidationException::withMessages(['lines' => "{$shop->name} is closed. Leave it out of the order."]);
            }

            if (blank($product->vat_rate_id)) {
                throw ValidationException::withMessages(['lines' => "{$product->name} ".Country::tax('has no VAT rate. Set one on the product first.')]);
            }

            $key = $shop->id.'|'.$supplier->id;
            $orders[$key] ??= ['shop' => $shop, 'supplier' => $supplier, 'lines' => [], 'net' => '0.00'];

            if (in_array($product->id, array_column($orders[$key]['lines'], 'productId'), true)) {
                throw ValidationException::withMessages(['lines' => "{$product->name} is on the order for {$shop->name} twice."]);
            }

            $link = $links->get($supplier->id.'|'.$product->id);
            $caseQty = max(1, (int) ($link->case_qty ?? 1));
            $unitCost = $link !== null && ! Money::isZero($link->case_cost ?? 0)
                ? Money::round(bcdiv(Money::parse($link->case_cost), (string) $caseQty, 8), 4)
                : Money::normalise($product->cost_price ?? 0, 4);
            $units = (int) $line['cases'] * $caseQty;
            $cost = Money::round(Money::mul((string) $units, $unitCost, 4), 2);

            $orders[$key]['lines'][] = [
                'productId' => $product->id, 'name' => (string) $product->name, 'cases' => (int) $line['cases'], 'caseQty' => $caseQty,
                'units' => $units, 'unitCost' => $unitCost, 'vatRateId' => $product->vat_rate_id, 'cost' => $cost,
            ];
            $orders[$key]['net'] = Money::add($orders[$key]['net'], $cost, 2);
        }

        return array_values($orders);
    }

    /**
     * A plain-English summary: "Leeds from Booker: 3 lines, about £45.20; …".
     *
     * @param  list<PlanOrder>  $orders
     */
    public static function describe(array $orders): string
    {
        return implode('; ', array_map(fn (array $o) => "{$o['shop']->name} from {$o['supplier']->name}: ".count($o['lines'])
            .' '.(count($o['lines']) === 1 ? 'line' : 'lines').', about '.MoneyFormat::format($o['net'], ukStyle: MoneyFormat::AS_GIVEN).' '.Country::tax('ex VAT'), $orders));
    }
}
