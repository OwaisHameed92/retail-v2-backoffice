<?php

namespace App\Domain\Ai\Tools\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiWriteTool;
use App\Domain\Ai\Data\AiProposal;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Enums\ToolKind;
use App\Domain\Ai\Exceptions\AiActionFailed;
use App\Domain\Ai\Support\Portal\AssistantLinks;
use App\Domain\Ai\Support\Portal\ShopPin;
use App\Domain\Purchasing\Actions\CreateReorderOrders;
use App\Domain\Purchasing\Queries\PurchasingPage;
use App\Domain\Purchasing\Reorder\ReorderCalculator;
use App\Domain\Purchasing\Reorder\ReorderFilters;
use App\Domain\Purchasing\Reorder\ReorderOrderPlan;
use App\Domain\Purchasing\Reorder\ReorderSuggestions;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\PurchaseOrder;

/**
 * Write: the reorder suggestions (module 6.4) for one shop (and optionally one supplier), proposed as draft
 * head-office orders, one per supplier. Opens the suggestions page as an answer link. Nothing changes until the user
 * confirms; then CreateReorderOrders drafts exactly the previewed lines (nothing is sent to a supplier). The model may
 * change the cases of suggested products (0 leaves one out). `purchasing.manage` and every shop, as the order form.
 */
final class SuggestReorder implements AiWriteTool
{
    public function __construct(
        private readonly ReorderSuggestions $suggestions,
        private readonly CreateReorderOrders $create,
        private readonly AssistantLinks $links,
    ) {}

    public function name(): string
    {
        return 'suggest_reorder';
    }

    public function description(): string
    {
        return 'Work out what one shop should reorder now (from its sales, stock, open orders, supplier lead times, case '
            .'sizes, shelf life and seasonal events) and propose DRAFT purchase orders, one per supplier. Nothing changes '
            .'until the user confirms in the app, and even then the orders stay drafts. Optionally one supplier only, and '
            .'optionally change the suggested cases of some products (0 leaves a product out). Shop ids from '
            .'get_company_overview, supplier ids from find_products.';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shop_id' => ['type' => 'string', 'description' => 'The shop to reorder for.'],
                'supplier_id' => ['type' => 'string', 'description' => 'Optional: one supplier only.'],
                'lines' => [
                    'type' => 'array',
                    'maxItems' => 200,
                    'description' => 'Optional changes to the suggested cases.',
                    'items' => [
                        'type' => 'object',
                        'properties' => [
                            'product_id' => ['type' => 'string'],
                            'supplier_id' => ['type' => 'string'],
                            'cases' => ['type' => 'integer', 'minimum' => 0],
                        ],
                        'required' => ['product_id', 'cases'],
                        'additionalProperties' => false,
                    ],
                ],
            ],
            'required' => ['shop_id'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'shop_id' => ['required', 'string', new ValidUlid],
            'supplier_id' => ['nullable', 'string', 'max:64'],
            'lines' => ['nullable', 'array', 'max:'.ReorderOrderPlan::MAX_LINES],
            'lines.*.product_id' => ['required', 'string', 'max:64', 'distinct'],
            'lines.*.supplier_id' => ['nullable', 'string', 'max:64'],
            'lines.*.cases' => ['required', 'integer', 'min:0', 'max:10000'],
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

    /**
     * @return AiProposal|array<string, mixed>
     */
    public function handle(array $input, AiContext $context): AiProposal|array
    {
        $shop = $this->shop($input);
        $supplier = isset($input['supplier_id']) ? (string) $input['supplier_id'] : null;
        $lines = $this->suggestions->handle(ReorderFilters::make($shop->id, $supplier, null, 'all'))['lines'];
        $this->links->add('Reorder suggestions · '.$shop->name, '/app/purchasing/suggestions', ['shop' => $shop->id, 'supplier' => $supplier], ShopPin::resolve($shop->id));

        $changes = [];
        foreach ((array) ($input['lines'] ?? []) as $change) {
            $changes[(string) $change['product_id']] = (int) $change['cases'];
        }

        $chosen = [];
        foreach ($lines as $line) {
            $cases = $changes[$line['productId']] ?? $line['suggestedCases'];

            if ($cases > 0) {
                $chosen[] = ['line' => $line, 'cases' => $cases];
            }
        }

        if ($chosen === []) {
            return ['shop' => $shop->name, 'linesToOrder' => 0, 'note' => 'Nothing needs ordering for this shop now. The suggestions page is linked for a closer look.'];
        }

        $plan = ReorderOrderPlan::build(array_map(fn (array $c) => [
            'shopId' => $shop->id, 'supplierId' => $c['line']['supplierId'], 'productId' => $c['line']['productId'], 'cases' => $c['cases'],
        ], $chosen));
        $top = array_map(fn (array $c) => "{$c['cases']} × {$c['line']['caseQty']} {$c['line']['name']}"
            .($c['line']['coverDays'] !== null ? ' ('.ReorderCalculator::qty($c['line']['coverDays'], 1).' days of stock left)' : ''), array_slice($chosen, 0, 5));
        $count = count($plan);

        return new AiProposal(
            preview: 'Draft '.($count === 1 ? 'an order' : "{$count} orders").' from the reorder suggestions. '.ReorderOrderPlan::describe($plan)
                .'. Includes '.implode('; ', $top).(count($chosen) > 5 ? '; and '.(count($chosen) - 5).' more' : '')
                .'. They are saved as drafts; nothing is sent to a supplier.',
            input: array_filter([
                'shop_id' => $shop->id,
                'supplier_id' => $supplier,
                'lines' => array_map(fn (array $c) => ['product_id' => $c['line']['productId'], 'supplier_id' => $c['line']['supplierId'], 'cases' => $c['cases']], $chosen),
            ], fn (mixed $v) => $v !== null),
        );
    }

    public function execute(array $input, AiContext $context): array
    {
        $shop = $this->shop($input);
        $lines = array_values(array_filter((array) ($input['lines'] ?? []), fn (array $l) => (int) $l['cases'] > 0 && isset($l['supplier_id'])));

        if ($lines === []) {
            throw AiActionFailed::because('There was nothing left to order.');
        }

        $orders = $this->create->handle(array_map(fn (array $l) => [
            'shopId' => $shop->id, 'supplierId' => (string) $l['supplier_id'], 'productId' => (string) $l['product_id'], 'cases' => (int) $l['cases'],
        ], $lines), 'Drafted with the AI assistant from the reorder suggestions.');

        return [
            'status' => 'draft',
            'orders' => array_map(fn (PurchaseOrder $o) => ['orderId' => $o->id, 'reference' => $o->reference, 'href' => '/app/purchasing/orders/'.$o->id], $orders),
            'href' => count($orders) === 1 ? '/app/purchasing/orders/'.$orders[0]->id : '/app/purchasing/orders?origin=headOffice&status=draft',
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     */
    private function shop(array $input): Branch
    {
        if (! PurchasingPage::canManage()) {
            throw AiActionFailed::because('Only a user who can see every shop can draft head-office orders.');
        }

        $shop = Branch::query()->findOrFail($input['shop_id']);

        if (! $shop->is_active) {
            throw AiActionFailed::because("{$shop->name} is closed. Choose an open shop.");
        }

        return $shop;
    }
}
