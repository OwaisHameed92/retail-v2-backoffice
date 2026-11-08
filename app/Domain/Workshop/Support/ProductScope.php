<?php

namespace App\Domain\Workshop\Support;

use App\Domain\TillData\Models\Category;
use App\Domain\TillData\Models\TillSetting;
use App\Domain\Workshop\Models\WorkshopConnection;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;

/**
 * Which products BikeBook sees for a connection (WORKSHOP-BIKEBOOK.md §4.1), from the shop's settings the till pushes:
 * `workshop.product_scope` (`all` | `categories`, default `categories`; the connection's `product_scope` overrides it
 * when set), `workshop.scope_category_ids` (JSON list; a sub-category counts when its parent is listed) and
 * `workshop.new_categories_included` (a category made after the list was saved joins it). The product's own
 * `workshopExposure` wins: `always` is in, `never` is out. Only active, not archived, not merged products.
 *
 * Applies to top-level rows (single products and variant parents); variants follow their parent.
 */
final class ProductScope
{
    public const KEYS = ['workshop.product_scope', 'workshop.scope_category_ids', 'workshop.new_categories_included'];

    /**
     * @param  list<string>  $categoryIds  listed categories with their sub-categories
     */
    private function __construct(
        public readonly string $mode,
        public readonly array $categoryIds,
        public readonly ?CarbonImmutable $changedAt,
    ) {}

    /** Reads the shop's settings (branch scope, else company scope). Call inside the connection's company. */
    public static function for(WorkshopConnection $connection): self
    {
        $settings = TillSetting::query()->whereIn('setting_key', self::KEYS)
            ->where(fn ($q) => $q->where(fn ($b) => $b->where('scope', 'branch')->where('scope_id', $connection->branch_id))
                ->orWhere('scope', 'company'))
            ->get(['scope', 'setting_key', 'value', 'updated_at'])
            ->sortBy(fn (TillSetting $s) => $s->scope?->value === 'branch' ? 0 : 1)
            ->unique('setting_key')->keyBy('setting_key');

        $mode = strtolower(trim((string) ($connection->product_scope ?? $settings->get('workshop.product_scope')->value ?? 'categories')));
        $listed = json_decode((string) ($settings->get('workshop.scope_category_ids')->value ?? '[]'), true);
        $listed = array_values(array_filter(is_array($listed) ? $listed : [], fn ($id) => is_string($id) && $id !== ''));
        $listSetAt = $settings->get('workshop.scope_category_ids')?->updated_at;

        if (in_array(strtolower((string) ($settings->get('workshop.new_categories_included')->value ?? 'false')), ['true', '1'], true) && $listSetAt !== null) {
            $listed = [...$listed, ...Category::query()->where('created_at', '>', $listSetAt)->pluck('id')->map(fn ($id) => (string) $id)->all()];
        }

        $changedAt = $settings->max(fn (TillSetting $s) => $s->updated_at);

        return new self($mode === 'all' ? 'all' : 'categories', self::withChildren($listed), $changedAt instanceof CarbonImmutable ? $changedAt : null);
    }

    /** Limits a query on `products` (top-level rows) to the products in scope. */
    public function apply(Builder $query, string $table = 'products'): Builder
    {
        return self::active($query, $table)->where(function (Builder $q) use ($table) {
            $q->where("{$table}.workshop_exposure", 'always')->orWhere(function (Builder $auto) use ($table) {
                $auto->where(fn (Builder $e) => $e->whereNull("{$table}.workshop_exposure")->orWhere("{$table}.workshop_exposure", '<>', 'never'));

                if ($this->mode !== 'all') {
                    $ids = $this->categoryIds === [] ? ['-'] : $this->categoryIds;
                    $auto->where(fn (Builder $c) => $c->whereIn("{$table}.category_id", $ids)->orWhereIn("{$table}.sub_category_id", $ids));
                }
            });
        });
    }

    /** Active, not archived, not merged, not deleted. */
    public static function active(Builder $query, string $table = 'products'): Builder
    {
        return $query->where("{$table}.is_active", true)->whereNull("{$table}.archived_at")
            ->whereNull("{$table}.merged_into_product_id")->whereNull("{$table}.deleted_at");
    }

    /** The scope settings changed at or after this instant (so a delta must re-send every product's state). */
    public function changedSince(?CarbonImmutable $since): bool
    {
        return $since !== null && $this->changedAt !== null && $this->changedAt->greaterThanOrEqualTo($since);
    }

    /**
     * @param  list<string>  $ids
     * @return list<string>
     */
    private static function withChildren(array $ids): array
    {
        $all = array_values(array_unique($ids));

        for ($level = 0; $level < 3 && $all !== []; $level++) {
            $children = Category::query()->whereIn('parent_category_id', $all)->whereNotIn('id', $all)->pluck('id')->map(fn ($id) => (string) $id)->all();

            if ($children === []) {
                break;
            }

            $all = [...$all, ...$children];
        }

        return $all;
    }
}
