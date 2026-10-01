<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Stock\Data\StockFilters;
use App\Domain\Stock\Queries\StockOnHand;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Enums\Ability;

/**
 * Read: stock on hand now (module 5.1 StockOnHand, the till's own `branch_products` and its low-stock rule): totals,
 * and the lines matching a search and/or status (low, out, negative), worst first.
 */
final class GetStock extends PortalReadTool
{
    public function name(): string
    {
        return 'get_stock';
    }

    public function description(): string
    {
        return 'Stock on hand now, from the tills: totals (lines, units, value at cost, how many are low, out or '
            .'negative) and up to 25 lines, worst first. Filter by a product search (part of the name, SKU or exact '
            .'barcode) and/or a status: low (at or below the low-stock point, out included), out, negative. Use it for '
            .'"what is low on stock", "how many X do we have".';
    }

    public function inputSchema(): array
    {
        return self::object([
            'search' => ['type' => 'string', 'description' => 'Optional product search.'],
            'status' => ['type' => 'string', 'enum' => StockFilters::STATUSES, 'description' => 'Optional.'],
            'shop_id' => ShopPin::schema(),
            'limit' => ['type' => 'integer', 'minimum' => 1, 'maximum' => 25, 'description' => 'Lines to list. Default 15.'],
        ]);
    }

    public function rules(): array
    {
        return [
            'search' => ['nullable', 'string', 'max:80'],
            'status' => ['nullable', 'string', 'in:'.implode(',', StockFilters::STATUSES)],
            'shop_id' => ['nullable', 'string', new ValidUlid],
            'limit' => ['nullable', 'integer', 'min:1', 'max:25'],
        ];
    }

    public function requiredAbility(): Ability
    {
        return Ability::StockView;
    }

    public function handle(array $input, AiContext $context): array
    {
        $shop = ShopPin::resolve($input['shop_id'] ?? null);
        $search = trim((string) ($input['search'] ?? '')) ?: null;
        $status = is_string($input['status'] ?? null) ? $input['status'] : null;
        $filters = new StockFilters(shop: $shop->id, shopLocked: $shop->pinned, search: $search, status: $status);
        $companyId = app(CurrentCompany::class)->require()->getKey();
        $stock = app(StockOnHand::class);
        $page = $stock->page($companyId, $filters, 1, (int) ($input['limit'] ?? 15));

        $this->links->add('Stock on hand · '.$shop->name, '/app/stock', [
            'shop' => $shop->pinned ? null : ($shop->id ?? 'all'), 'search' => $search, 'status' => $status,
        ], $shop);

        return [
            ...$shop->toArray(),
            'search' => $search,
            'status' => $status,
            'totals' => $stock->summary($companyId, $filters),
            'matchingLines' => $page['total'],
            'lines' => array_map(fn (array $r) => array_filter([
                'productId' => $r['productId'],
                'name' => $r['name'],
                'department' => $r['department'],
                'onHand' => $r['onHand'],
                'lowAt' => $r['lowAt'] ?? null,
                'shops' => $r['shops'] ?? null,
                'shopsLow' => $r['lowShops'] ?? null,
                'shopsOut' => $r['outShops'] ?? null,
                'valueAtCost' => $r['value'],
                'status' => $r['status'],
            ], fn (mixed $v) => $v !== null), $page['rows']),
        ];
    }
}
