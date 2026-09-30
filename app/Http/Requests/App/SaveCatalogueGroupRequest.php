<?php

namespace App\Http\Requests\App;

use App\Domain\TillData\Enums\AgeRule;
use App\Domain\TillData\Enums\NegativeStockPolicy;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * A department or category (module 4.2). Route: `company.can:catalogue.manage`. Category-only members are ignored for
 * a department; SaveDepartment / SaveCategory check names and links against the business's data.
 */
class SaveCatalogueGroupRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $category = $this->routeIs('app.products.categories.*');

        return [
            'name' => ['required', 'string', 'max:100'],
            'colour_hex' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['required', 'boolean'],
            'is_visible_on_till' => ['required', 'boolean'],
            'default_vat_rate_id' => ['nullable', 'string', 'size:26'],
            ...($category ? [
                'department_id' => ['required', 'string', 'size:26'],
                'parent_category_id' => ['nullable', 'string', 'size:26'],
                'age_rule_default' => ['required', Rule::enum(AgeRule::class)],
                'negative_stock_mode' => ['nullable', Rule::enum(NegativeStockPolicy::class)],
            ] : [
                'show_in_report' => ['required', 'boolean'],
            ]),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['colour_hex.regex' => 'Choose a colour.', 'department_id.required' => 'Choose a department.'];
    }

    /**
     * @return array<string, mixed>
     */
    public function attributesToSave(): array
    {
        $data = $this->validated();

        if (($data['position'] ?? null) === null) {
            unset($data['position']);
        }

        return $data;
    }
}
