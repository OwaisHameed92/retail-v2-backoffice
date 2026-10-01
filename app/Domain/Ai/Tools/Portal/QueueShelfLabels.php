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
use App\Domain\Labels\Actions\AddLabelsToQueue;
use App\Domain\Shared\Rules\ValidUlid;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Models\Product;

/**
 * Write (module 6.4): add products to one shop's shelf-label queue (gap #6). Proposes only; after the user confirms
 * it calls AddLabelsToQueue (deduped, audited). Nothing is printed. `labels.print`; a one-shop user always queues for
 * their own shop. Only products on sale can be queued.
 */
final class QueueShelfLabels implements AiWriteTool
{
    public const MAX = 100;

    public function __construct(private readonly AddLabelsToQueue $add, private readonly AssistantLinks $links) {}

    public function name(): string
    {
        return 'queue_labels';
    }

    public function description(): string
    {
        return 'Propose adding products to a shop\'s shelf-label queue, so new shelf-edge labels can be printed for them. '
            .'Nothing changes until the user confirms in the app, and nothing is printed. Product ids from find_products, '
            .'shop ids from get_company_overview (a user limited to one shop always gets their own shop).';
    }

    public function inputSchema(): array
    {
        return [
            'type' => 'object',
            'properties' => [
                'shop_id' => ['type' => 'string', 'description' => 'The shop whose labels these are.'],
                'product_ids' => ['type' => 'array', 'minItems' => 1, 'maxItems' => self::MAX, 'items' => ['type' => 'string']],
            ],
            'required' => ['product_ids'],
            'additionalProperties' => false,
        ];
    }

    public function rules(): array
    {
        return [
            'shop_id' => ['nullable', 'string', new ValidUlid],
            'product_ids' => ['required', 'array', 'min:1', 'max:'.self::MAX],
            'product_ids.*' => ['required', 'string', 'max:64', 'distinct'],
        ];
    }

    public function requiredAbility(): Ability
    {
        return Ability::LabelsPrint;
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
        [$shop, $products] = $this->resolve($input);
        $names = array_map(fn (Product $p) => (string) $p->name, array_slice($products, 0, 8));
        $count = count($products);
        $this->links->add('Shelf labels · '.$shop->name, '/app/labels', ['shop' => $shop->id], ShopPin::resolve($shop->id));

        return new AiProposal(
            preview: 'Add shelf labels for '.($count === 1 ? '1 product' : "{$count} products")." to {$shop->name}'s label queue: "
                .implode(', ', $names).($count > 8 ? ' and '.($count - 8).' more' : '').'. Nothing is printed until someone prints the queue.',
            input: ['shop_id' => $shop->id, 'product_ids' => array_map(fn (Product $p) => $p->id, $products)],
        );
    }

    public function execute(array $input, AiContext $context): array
    {
        [$shop, $products] = $this->resolve($input);
        $queued = $this->add->handle($shop, ['product_ids' => array_map(fn (Product $p) => $p->id, $products)]);

        return ['queued' => $queued, 'shop' => $shop->name, 'href' => '/app/labels?shop='.$shop->id];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{0: Branch, 1: list<Product>}
     */
    private function resolve(array $input): array
    {
        $pin = ShopPin::resolve($input['shop_id'] ?? null);

        if ($pin->id === null) {
            throw AiActionFailed::because('Say which shop the labels are for.');
        }

        $shop = Branch::query()->findOrFail($pin->id);

        if (! $shop->is_active) {
            throw AiActionFailed::because("{$shop->name} is closed. Choose an open shop.");
        }

        $ids = array_values(array_map('strval', (array) $input['product_ids']));
        $products = Product::query()->whereKey($ids)->where('is_active', true)->orderBy('name')->get()->all();

        if (count($products) !== count($ids)) {
            throw AiActionFailed::because('Some of those products were not found in this business or are not on sale.');
        }

        return [$shop, $products];
    }
}
