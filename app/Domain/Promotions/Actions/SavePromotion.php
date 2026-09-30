<?php

namespace App\Domain\Promotions\Actions;

use App\Domain\Promotions\Support\PromotionChecks;
use App\Domain\Promotions\Support\PromotionTypes;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Models\PromotionItem;
use App\Domain\TillData\Models\PromotionRule;
use BackedEnum;
use Carbon\CarbonInterface;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

/**
 * Creates or edits an offer (PromotionRule, module 4.3) with its items. Hub-owned: saves go through the models, so
 * every till gets the rule in its next pull (the till keeps only its own shop's and every-shop rules: `branch_id`
 * null = every shop). A rule and its items keep their ULIDs. `is_group_offer` is worked out, never taken from input;
 * members the form does not show (member value, price tiers, redemptions so far, customer group, days) keep their
 * value; the form's members are all saved (one left out takes its default). An edit raises `row_version` by one; nothing is written when nothing changed.
 */
final class SavePromotion
{
    public const FIELDS = [
        'name', 'type', 'scope', 'target_id', 'percent', 'amount_off', 'deal_price', 'buy_quantity', 'get_quantity', 'min_quantity',
        'priority', 'allow_stack', 'is_exclusive', 'max_redemptions_per_sale', 'max_redemptions_total', 'requires_coupon', 'coupon_code',
        'branch_id', 'is_hfss_safe', 'effective_from', 'effective_to', 'time_from', 'time_to', 'is_active',
    ];

    /** The whole form is saved: a member left out takes this value. */
    private const DEFAULTS = [
        'scope' => 'product', 'target_id' => '', 'branch_id' => null, 'effective_to' => null, 'time_from' => null, 'time_to' => null,
        'requires_coupon' => false, 'coupon_code' => '', 'allow_stack' => false, 'is_exclusive' => false, 'is_hfss_safe' => false,
        'is_active' => true, 'max_redemptions_per_sale' => null, 'max_redemptions_total' => null, 'priority' => 0, 'min_quantity' => 1,
    ];

    public function __construct(private readonly PromotionChecks $checks, private readonly RecordAudit $audit) {}

    /**
     * @param  array<string, mixed>  $data  FIELDS (dates `Y-m-d`, times `H:i`, money in pounds)
     * @param  list<array{id?: string|null, scope: string, target_id: string, group_no?: int|string|null, quantity?: int|string|null, is_excluded?: bool|null}>  $items
     */
    public function handle(?PromotionRule $rule, array $data, array $items = []): PromotionRule
    {
        $created = $rule === null;
        $data = $this->normalise(Arr::only($data, self::FIELDS));
        $itemType = in_array($data['type'], PromotionTypes::ITEM_TYPES, true);
        $this->checks->check($rule, $data, $itemType ? $items : []);

        return DB::transaction(function () use ($rule, $data, $items, $created, $itemType) {
            $rule ??= (new PromotionRule)->forceFill([
                'member_value' => '0', 'price_tiers' => null, 'redemption_count' => 0, 'customer_group_id' => null,
                'seasonal_event_id' => '', 'days' => '',
            ]);
            $wasItemType = in_array($rule->type?->value, PromotionTypes::ITEM_TYPES, true);
            $before = $created ? null : Arr::only($rule->attributesToArray(), self::FIELDS);

            $data['is_group_offer'] = PromotionTypes::isGroupOffer($data['type'], $data['scope'], (int) $data['min_quantity']);
            $dirty = $created ? array_keys($data) : array_values(array_filter(array_keys($data), fn (string $key) => ! self::same($rule->getAttribute($key), $data[$key])));
            $rule->forceFill(Arr::only($data, $dirty));

            if ($created || $dirty !== []) {
                if (! $created) {
                    $rule->row_version = (int) $rule->row_version + 1;
                }
                $rule->save();
            }

            $itemsChanged = $itemType ? $this->syncItems($rule, $items) : ($wasItemType && $this->syncItems($rule, []));

            if ($created || $dirty !== [] || $itemsChanged) {
                $this->audit->handle($created ? 'promotion.created' : 'promotion.updated', $rule,
                    $before === null ? null : Arr::only($before, $dirty), Arr::only($rule->attributesToArray(), $created ? ['name', 'type', 'scope', 'branch_id'] : $dirty),
                    ['name' => $rule->name, 'items_changed' => $itemsChanged]);
            }

            return $rule;
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalise(array $data): array
    {
        $data = [...self::DEFAULTS, ...$data];
        $uses = PromotionTypes::USES[$data['type']] ?? [];

        foreach (PromotionTypes::NUMERIC as $member) {
            $value = in_array($member, $uses, true) ? ($data[$member] ?? 0) : 0;
            $data[$member] = in_array($member, ['buy_quantity', 'get_quantity'], true) ? (int) $value : Money::normalise((string) $value);
        }

        if (in_array($data['type'], PromotionTypes::ITEM_TYPES, true)) {
            $data['scope'] = 'itemGroup';
            $data['target_id'] = '';
        } elseif ($data['scope'] === 'basket') {
            $data['target_id'] = '';
        }

        $data['name'] = trim((string) $data['name']);
        $data['coupon_code'] = trim((string) ($data['coupon_code'] ?? ''));
        $data['min_quantity'] = max(1, (int) ($data['min_quantity'] ?? 1));
        $data['priority'] = (int) ($data['priority'] ?? 0);

        foreach (['time_from', 'time_to'] as $time) {
            $data[$time] = ($data[$time] ?? null) ? substr((string) $data[$time], 0, 5).':00' : null;
        }

        return $data;
    }

    /** Same value once cast (enums, dates, numbers to 4 places), so an unchanged form writes nothing. */
    private static function same(mixed $a, mixed $b): bool
    {
        [$a, $b] = array_map(fn ($v) => $v instanceof BackedEnum ? $v->value : ($v instanceof CarbonInterface ? $v->toDateString() : $v), [$a, $b]);

        return match (true) {
            is_bool($a) || is_bool($b) => (bool) $a === (bool) $b,
            $a === null || $b === null => $a === $b,
            is_numeric($a) && is_numeric($b) => bccomp((string) $a, (string) $b, 4) === 0,
            default => (string) $a === (string) $b,
        };
    }

    /**
     * Items keep their ids; removed ones are soft-deleted (tills get the delete). Returns whether anything changed.
     *
     * @param  list<array{id?: string|null, scope: string, target_id: string, group_no?: int|string|null, quantity?: int|string|null, is_excluded?: bool|null}>  $items
     */
    private function syncItems(PromotionRule $rule, array $items): bool
    {
        $existing = PromotionItem::query()->where('promotion_rule_id', $rule->id)->get()->keyBy('id');
        $kept = [];
        $changed = false;

        foreach ($items as $input) {
            $item = isset($input['id']) && $existing->has($input['id']) ? $existing->get($input['id']) : new PromotionItem;
            $item->forceFill([
                'promotion_rule_id' => $rule->id, 'scope' => $input['scope'], 'target_id' => $input['target_id'],
                'group_no' => max(1, (int) ($input['group_no'] ?? 1)), 'quantity' => max(1, (int) ($input['quantity'] ?? 1)),
                'is_excluded' => (bool) ($input['is_excluded'] ?? false),
            ]);
            $item->attribute_filter ??= null;

            if (! $item->exists || $item->isDirty()) {
                if ($item->exists) {
                    $item->row_version = (int) $item->row_version + 1;
                }
                $item->save();
                $changed = true;
            }
            $kept[] = $item->id;
        }

        foreach ($existing->except($kept) as $gone) {
            $gone->delete();
            $changed = true;
        }

        return $changed;
    }
}
