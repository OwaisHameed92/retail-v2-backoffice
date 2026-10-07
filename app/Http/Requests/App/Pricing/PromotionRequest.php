<?php

namespace App\Http\Requests\App\Pricing;

use App\Domain\Promotions\Support\PromotionTypes;
use App\Domain\Shared\Country\CountryModules;
use App\Domain\Shared\Country\LocalText;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\PromotionScope;
use App\Domain\TillData\Enums\PromotionType;
use App\Domain\TillData\Models\PromotionRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * The offer form (module 4.3) and ending an offer (`app.promotions.end`: no body). Route: `company.can:promotions.manage`.
 * Formats only; SavePromotion checks what each type needs and that targets exist. A one-shop user may make and change
 * only offers for their own shop: every-shop offers and other shops' are read-only for them (403).
 */
class PromotionRequest extends FormRequest
{
    private const MONEY = 'regex:/^\d{1,8}(\.\d{1,2})?$/';

    public function authorize(): bool
    {
        $restricted = app(CurrentCompany::class)->restrictedBranchId();

        if ($restricted === null) {
            return true;
        }

        $id = $this->route('promotion');
        $owner = is_string($id) ? PromotionRule::query()->whereKey($id)->value('branch_id') : $restricted;

        return $owner === $restricted && ($this->routeIs('app.promotions.end') || $this->input('branch_id') === $restricted);
    }

    protected function prepareForValidation(): void
    {
        $clean = [];
        foreach ($this->all() as $key => $value) {
            $clean[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }
        $this->replace($clean);

        // Phase P10: where the country profile hides HFSS rules, the form has no "Allowed on HFSS food": keep it.
        $id = $this->route('promotion');
        $this->merge(CountryModules::keep(
            [CountryModules::HFSS => ['is_hfss_safe']],
            fn () => is_string($id) ? PromotionRule::query()->find($id) : null,
            ['is_hfss_safe' => false],
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        if ($this->routeIs('app.promotions.end')) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['required', Rule::enum(PromotionType::class)],
            'scope' => ['required', Rule::enum(PromotionScope::class)],
            'target_id' => ['nullable', 'string', 'max:64'],
            'percent' => ['nullable', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'amount_off' => ['nullable', self::MONEY],
            'deal_price' => ['nullable', self::MONEY],
            'buy_quantity' => ['nullable', 'integer', 'min:0', 'max:999'],
            'get_quantity' => ['nullable', 'integer', 'min:0', 'max:999'],
            'min_quantity' => ['nullable', 'integer', 'min:1', 'max:999'],
            'priority' => ['nullable', 'integer', 'min:0', 'max:999'],
            'allow_stack' => ['boolean'],
            'is_exclusive' => ['boolean'],
            'max_redemptions_per_sale' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'max_redemptions_total' => ['nullable', 'integer', 'min:1', 'max:9999999'],
            'requires_coupon' => ['boolean'],
            'coupon_code' => ['nullable', 'string', 'max:64'],
            'branch_id' => ['nullable', 'string', 'size:26'],
            'is_hfss_safe' => ['boolean'],
            'effective_from' => ['required', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'time_from' => ['nullable', 'date_format:H:i'],
            'time_to' => ['nullable', 'date_format:H:i'],
            'is_active' => ['boolean'],
            'price_tiers' => ['nullable', 'string', 'max:400'],
            'days' => ['nullable', 'array', 'max:7'],
            'days.*' => ['string', Rule::in(PromotionTypes::DAYS)],
            'items' => ['nullable', 'array', 'max:200'],
            'items.*.id' => ['nullable', 'string', 'size:26'],
            'items.*.scope' => ['required', Rule::in(PromotionTypes::ITEM_SCOPES)],
            'items.*.target_id' => ['required', 'string', 'size:26'],
            'items.*.group_no' => ['nullable', 'integer', 'min:0', 'max:9'],
            'items.*.quantity' => ['nullable', 'integer', 'min:1', 'max:99'],
            'items.*.is_excluded' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'percent.regex' => 'Enter a percentage, e.g. 10 or 12.5.',
            'amount_off.regex' => LocalText::currency('Enter an amount in pounds, e.g. 0.50.'),
            'deal_price.regex' => LocalText::currency('Enter a price in pounds, e.g. 2.00.'),
            'items.*.target_id.required' => 'Choose an item.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function promotion(): array
    {
        $data = $this->safe()->except('items');

        return [...$data, 'target_id' => (string) ($data['target_id'] ?? ''), 'branch_id' => $data['branch_id'] ?? null,
            'effective_to' => $data['effective_to'] ?? null, 'allow_stack' => (bool) ($data['allow_stack'] ?? false),
            'is_exclusive' => (bool) ($data['is_exclusive'] ?? false), 'requires_coupon' => (bool) ($data['requires_coupon'] ?? false),
            'is_hfss_safe' => (bool) ($data['is_hfss_safe'] ?? false), 'is_active' => (bool) ($data['is_active'] ?? true),
            'days' => array_values((array) ($data['days'] ?? []))];
    }

    /**
     * @return list<array{id?: string|null, scope: string, target_id: string, group_no?: int|string|null, quantity?: int|string|null, is_excluded?: bool|null}>
     */
    public function items(): array
    {
        /** @var list<array{id?: string|null, scope: string, target_id: string, group_no?: int|string|null, quantity?: int|string|null, is_excluded?: bool|null}> $items */
        $items = array_values((array) $this->validated('items', []));

        return $items;
    }
}
