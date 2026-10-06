<?php

namespace App\Http\Requests\App;

use App\Domain\Catalogue\Support\ProductFields;
use App\Domain\Shared\Country\Country;
use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillData\Enums\NegativeStockPolicy;
use App\Domain\TillData\Enums\UnitType;
use App\Http\Requests\App\Setup\CompanyWideWriteRequest;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

/**
 * The product form (module 4.2). Route: `company.can:catalogue.manage`. Formats only; SaveProduct checks the
 * business's data (department, category, VAT rate, units, barcodes on other products). Money in pounds: prices 2
 * decimal places, costs and quantities 4. Text limits are the columns' (255), as tills may send longer values than the form suggests.
 */
class SaveProductRequest extends CompanyWideWriteRequest
{
    private const MONEY = 'regex:/^\d{1,8}(\.\d{1,2})?$/';

    private const COST = 'regex:/^\d{1,8}(\.\d{1,4})?$/';

    protected function prepareForValidation(): void
    {
        $clean = [];

        foreach ($this->all() as $key => $value) {
            $clean[$key] = is_string($value) ? (trim($value) === '' ? null : trim($value)) : $value;
        }

        $this->replace($clean);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'short_name' => ['nullable', 'string', 'max:255'],
            'receipt_name' => ['nullable', 'string', 'max:255'],
            'sku' => ['nullable', 'string', 'max:255'],
            'brand' => ['nullable', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'department_id' => ['required', 'string', 'size:26'],
            'category_id' => ['required', 'string', 'size:26'],
            'sub_category_id' => ['nullable', 'string', 'size:26'],
            'vat_rate_id' => ['required', 'string', 'size:26'],
            'unit_type' => ['required', Rule::enum(UnitType::class)],
            'unit_code' => ['required', 'string', 'max:20'],
            'sell_price' => ['required', self::MONEY],
            'cost_price' => ['required', self::COST],
            'trade_price' => ['nullable', self::MONEY],
            'pmp_price' => ['nullable', self::MONEY],
            'min_stock_qty' => ['nullable', self::COST],
            'max_stock_qty' => ['nullable', self::COST],
            'reorder_qty' => ['nullable', self::COST],
            'negative_stock_mode' => ['nullable', Rule::enum(NegativeStockPolicy::class)],
            'bin_location' => ['nullable', 'string', 'max:255'],
            'age_rule' => ['required', Rule::enum(AgeRule::class)],
            'max_qty_per_sale' => ['nullable', 'integer', 'min:1', 'max:9999'],
            'max_qty_reason' => ['nullable', 'string', 'max:255'],
            'abv_percent' => ['nullable', 'numeric', 'min:0', 'max:100', 'regex:/^\d{1,3}(\.\d{1,2})?$/'],
            'volume_ml' => ['nullable', self::COST],
            'deposit_amount' => ['nullable', 'required_if_accepted:is_deposit_item', self::MONEY],
            'commodity_code' => ['nullable', 'string', 'max:20'],
            'net_mass_kg' => ['nullable', self::COST],
            'tile_colour_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'tile_emoji' => ['nullable', 'string', 'max:16'],
            'tile_position' => ['required', 'integer', 'min:0', 'max:9999'],
            ...array_fill_keys(ProductFields::BOOLEANS, ['required', 'boolean']),
            'barcodes' => ['present', 'array', 'max:20'],
            'barcodes.*.id' => ['nullable', 'string', 'size:26'],
            'barcodes.*.barcode' => ['required', 'string', 'max:50', 'regex:/^[0-9A-Za-z\-]+$/', 'distinct:ignore_case'],
            'barcodes.*.pack_qty' => ['required', 'integer', 'min:1', 'max:9999'],
            'barcodes.*.is_primary' => ['required', 'boolean'],
            'units' => ['present', 'array', 'max:10'],
            'units.*.id' => ['nullable', 'string', 'size:26'],
            'units.*.unit_id' => ['required', 'string', 'size:26'],
            'units.*.conversion_factor' => ['required', self::COST, 'not_in:0,0.0,0.00,0.000,0.0000'],
            'units.*.sell_price_inc_vat' => ['required', self::MONEY],
            'units.*.cost' => ['required', self::COST],
            'units.*.is_default_sell_unit' => ['required', 'boolean'],
            'units.*.is_purchase_unit' => ['required', 'boolean'],
            'units.*.is_default_purchase_unit' => ['required', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'regex' => 'Enter an amount in pounds, like 1.25.',
            'barcodes.*.barcode.regex' => 'A barcode is letters, digits and dashes only.',
            'barcodes.*.barcode.distinct' => 'This barcode is listed twice.',
            'tile_colour_hex.regex' => 'Choose a colour.',
            'units.*.conversion_factor.not_in' => 'How many of the base unit must be more than 0.',
            'deposit_amount.required_if_accepted' => 'Enter the deposit charged.',
            'department_id.required' => 'Choose a department.',
            'category_id.required' => 'Choose a category.',
            'vat_rate_id.required' => Country::tax('Choose a VAT rate.'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function productAttributes(): array
    {
        return Arr::only($this->validated(), ProductFields::EDITABLE);
    }

    /**
     * @return list<array{id?: string|null, barcode: string, pack_qty?: int|string|null, is_primary?: bool|null}>
     */
    public function barcodes(): array
    {
        return array_values(array_map(fn (array $b) => [
            'id' => $b['id'] ?? null, 'barcode' => (string) $b['barcode'], 'pack_qty' => (int) $b['pack_qty'], 'is_primary' => (bool) $b['is_primary'],
        ], $this->validated('barcodes', [])));
    }

    /**
     * @return list<array{id?: string|null, unit_id: string, conversion_factor: string, sell_price_inc_vat: string, cost: string, is_default_sell_unit?: bool|null, is_purchase_unit?: bool|null, is_default_purchase_unit?: bool|null}>
     */
    public function units(): array
    {
        return array_values(array_map(fn (array $u) => [
            'id' => $u['id'] ?? null, 'unit_id' => (string) $u['unit_id'], 'conversion_factor' => (string) $u['conversion_factor'],
            'sell_price_inc_vat' => (string) $u['sell_price_inc_vat'], 'cost' => (string) $u['cost'],
            'is_default_sell_unit' => (bool) $u['is_default_sell_unit'], 'is_purchase_unit' => (bool) $u['is_purchase_unit'],
            'is_default_purchase_unit' => (bool) $u['is_default_purchase_unit'],
        ], $this->validated('units', [])));
    }
}
