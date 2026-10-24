<?php

namespace App\Http\Requests\App\OnlineStore;

use App\Domain\OnlineStore\Actions\ChangeOrderLine;
use App\Domain\OnlineStore\Enums\OrderLineOutcome;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Change one line of an open online order while picking it (audit OSA-18): not available, a new quantity, or back
 * as ordered. The route checks `orders.online`; the order comes through PortalOrders. Checked by ChangeOrderLine.
 */
class ChangeOrderLineRequest extends FormRequest
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
        return [
            'action' => ['required', Rule::in([OrderLineOutcome::Unavailable->value, OrderLineOutcome::Changed->value, ChangeOrderLine::RESTORE])],
            'quantity' => ['nullable', 'string', 'max:12'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['action.*' => 'Choose what to do with the item.'];
    }
}
