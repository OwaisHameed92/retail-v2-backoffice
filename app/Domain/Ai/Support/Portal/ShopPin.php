<?php

namespace App\Domain\Ai\Support\Portal;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;

/**
 * Which shop a portal assistant tool reads (module 6.2). A user limited to one shop (membership `branch_id`, set on
 * CurrentCompany by ToolExecutor) always gets that shop, whatever the model asked for; anyone else gets the shop the
 * model named (looked up through the company scope, so another business's shop is "not found") or every shop.
 */
final readonly class ShopPin
{
    private function __construct(
        public ?string $id,
        public string $name,
        public bool $pinned,
        public ?string $note,
    ) {}

    public static function resolve(mixed $requested): self
    {
        $requested = is_string($requested) && $requested !== '' ? $requested : null;
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        if ($restricted !== null) {
            $branch = Branch::query()->withTrashed()->find($restricted, ['id', 'name']);

            return new self(
                $restricted,
                $branch->name ?? 'Your shop',
                true,
                $requested !== null && $requested !== $restricted
                    ? 'This user can only see their own shop, so these figures are for that shop only.'
                    : null,
            );
        }

        if ($requested === null) {
            return new self(null, 'All shops', false, null);
        }

        $branch = Branch::query()->withTrashed()->findOrFail($requested, ['id', 'name']);

        return new self($branch->id, $branch->name, false, null);
    }

    /** @return list<string>|null */
    public function branchIds(): ?array
    {
        return $this->id === null ? null : [$this->id];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_filter([
            'shop' => $this->name,
            'shopId' => $this->id,
            'limitedToOneShop' => $this->pinned ?: null,
            'note' => $this->note,
        ], fn (mixed $v) => $v !== null);
    }

    /**
     * JSON schema of the `shop_id` input.
     *
     * @return array<string, string>
     */
    public static function schema(): array
    {
        return ['type' => 'string', 'description' => 'Optional. One shop\'s id (from get_company_overview); leave out for every shop.'];
    }
}
