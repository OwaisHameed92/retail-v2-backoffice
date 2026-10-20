<?php

namespace App\Http\Requests\Api\Pda;

use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/pda/purchase-orders` and `PUT …/{id}` (legacy body): supplier, expected delivery date, notes, status
 * (draft | sent) and `items` (replace the lines). Quantities are whole units of the line's unit; costs up to 4
 * decimal places, as the portal's head-office order form. Ids are checked against the business by the action.
 */
class PdaPurchaseOrderRequest extends FormRequest
{
    private const COST = 'regex:/^\d{1,7}(\.\d{1,4})?$/';

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'supplier_id' => ['required', 'string', 'max:64'],
            'expected_delivery_date' => ['nullable', 'date_format:Y-m-d'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'status' => ['nullable', 'in:draft,sent'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*.product_id' => ['required', 'string', 'max:64', 'distinct'],
            'items.*.quantity_ordered' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.unit_cost' => ['nullable', self::COST],
            'items.*.conversion_factor' => ['nullable', 'integer', 'min:1', 'max:10000'],
            'items.*.base_unit_cost' => ['nullable', self::COST],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'items.required' => 'Add at least one product.',
            'items.min' => 'Add at least one product.',
            'items.*.product_id.distinct' => 'Each product may be on the order once.',
            'items.*.quantity_ordered.integer' => 'Order whole units.',
            'items.*.unit_cost.regex' => 'Enter the cost with up to 4 decimal places.',
            'items.*.base_unit_cost.regex' => 'Enter the cost with up to 4 decimal places.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function order(): array
    {
        return $this->validated();
    }
}
