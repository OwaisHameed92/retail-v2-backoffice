<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiWriteTool;
use App\Domain\Ai\Data\AiProposal;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Enums\ToolKind;
use App\Domain\Ai\Exceptions\AiActionFailed;
use App\Domain\Purchasing\Actions\SaveHeadOfficeOrder;
use App\Domain\Purchasing\Queries\PurchasingPage;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\ProductSupplier;
use App\Domain\TillData\Models\Supplier;

/**
 * Write: propose a DRAFT head-office order for one shop (module 5.2). Proposes only; after the user confirms it
 * calls SaveHeadOfficeOrder (status draft: nothing goes to the supplier), which does the numbering, totals, audit and
 * the pull to that shop. Same rule as the order form: `purchasing.manage` and every shop (not a one-shop user).
 * Case size and cost come from the product's supplier link (else the product cost, case of 1); VAT from the product.
 */
final class DraftPurchaseOrder implements AiWriteTool
{
    public function __construct(private readonly SaveHeadOfficeOrder $save) {}

    public function name(): string
    {
        return 'draft_purchase_order';
    }

    public function description(): string
    {
        return 'Propose a DRAFT head-office purchase order for one shop from one supplier. This does not change '
            .'anything: it creates a proposal the user must confirm in the app, and even then the order stays a draft '
            .'(not sent to the supplier). Get shop ids from get_company_overview and product / supplier ids from find_products.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shop_id' => ['type' => 'string', 'description' => 'The shop the order is for.'],
                'supplier_id' => ['type' => 'string', 'description' => 'The supplier id from find_products.'],
                'lines' => [
                    'type' => 'array',
                    'minItems' => 1,
                    'maxItems' => 50,
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'string'],
                            'cases' => ['type' => 'integer', 'minimum' => 1, 'description' => 'Number of cases.'],
                        ],
                        'required' => ['product_id', 'cases'],
                        'additionalProperties' => false,
                    ],
                ],
                'notes' => ['type' => 'string', 'description' => 'Optional note on the order.'],
            ],
            'required' => ['shop_id', 'supplier_id', 'lines'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'shop_id' => ['required', 'string', new ValidUlid],
            'supplier_id' => ['required', 'string', 'max:64'],
            'lines' => ['required', 'array', 'min:1', 'max:50'],
            'lines.*.product_id' => ['required', 'string', 'max:64', 'distinct'],
            'lines.*.cases' => ['required', 'integer', 'min:1', 'max:10000'],
            'notes' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function requiredAbility(): Ability
    {
        return Ability::PurchasingManage;
    }

    public function kind(): ToolKind
    {
        return ToolKind::Write;
    }

    public function audience(): ToolAudience
    {
        return ToolAudience::Tenant;
    }

    public function handle(array $input, AiContext $context): AiProposal
    {
        [$shop, $supplier, $lines] = $this->resolve($input);

        $text = implode('; ', array_map(fn (array $l) => "{$l['cases']} × {$l['caseQty']} {$l['name']} (£{$l['unitCost']} each)", $lines));
        $total = Money::sum(array_map(fn (array $l) => Money::mul(Money::mul((string) $l['cases'], (string) $l['caseQty'], 4), $l['unitCost'], 4), $lines), 4);

        return new AiProposal(
            preview: "Draft a head-office order for {$shop->name} from {$supplier->name}: {$text}. Cost about £".Money::round($total, 2)
                .' ex VAT. It is saved as a draft; nothing is sent to the supplier.',
            input: [
                'shop_id' => $shop->id,
                'supplier_id' => $supplier->id,
                'lines' => array_map(fn (array $l) => ['product_id' => $l['productId'], 'cases' => $l['cases']], $lines),
                'notes' => isset($input['notes']) ? (string) $input['notes'] : null,
            ],
        );
    }

    public function execute(array $input, AiContext $context): array
    {
        [$shop, $supplier, $lines] = $this->resolve($input);

        $order = $this->save->handle($shop, [
            'supplierId' => $supplier->id,
            'status' => 'draft',
            'notes' => ($input['notes'] ?? null) ?: 'Drafted with the AI assistant.',
            'lines' => array_map(fn (array $l) => [
                'productId' => $l['productId'], 'orderedCases' => $l['cases'], 'caseQty' => $l['caseQty'], 'looseUnits' => 0,
                'unitCost' => $l['unitCost'], 'vatRateId' => $l['vatRateId'],
            ], $lines),
        ]);

        return ['orderId' => $order->id, 'reference' => $order->reference, 'status' => 'draft', 'href' => '/app/purchasing/orders/'.$order->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Branch, 1: Supplier, 2: list<array{productId: string, name: string, cases: int, caseQty: int, unitCost: string, vatRateId: string}>}
     */
    private function resolve(array $input): array
    {
        if (! PurchasingPage::canManage()) {
            throw AiActionFailed::because('Only a user who can see every shop can draft head-office orders.');
        }

        $shop = Branch::query()->findOrFail($input['shop_id']);

        if (! $shop->is_active) {
            throw AiActionFailed::because("{$shop->name} is closed. Choose an open shop.");
        }

        $supplier = Supplier::query()->findOrFail($input['supplier_id']);
        $wanted = array_values((array) $input['lines']);
        $products = Product::query()->whereKey(array_column($wanted, 'product_id'))->get()->keyBy('id');
        $links = ProductSupplier::query()->where('supplier_id', $supplier->id)->whereIn('product_id', $products->keys())->get()->keyBy('product_id');
        $lines = [];

        foreach ($wanted as $line) {
            $product = $products->get($line['product_id']) ?? throw AiActionFailed::because('A product on the order was not found in this business.');
            $link = $links->get($product->id);
            $caseQty = max(1, (int) ($link->case_qty ?? 1));
            $unitCost = $link !== null && ! Money::isZero($link->case_cost)
                ? Money::round(bcdiv(Money::parse($link->case_cost), (string) $caseQty, 8), 4)
                : Money::normalise($product->cost_price, 4);

            $lines[] = [
                'productId' => $product->id, 'name' => $product->name, 'cases' => (int) $line['cases'], 'caseQty' => $caseQty,
                'unitCost' => $unitCost, 'vatRateId' => $product->vat_rate_id,
            ];
        }

        return [$shop, $supplier, $lines];
    }
}
