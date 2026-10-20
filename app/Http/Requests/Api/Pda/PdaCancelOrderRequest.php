<?php

namespace App\Http\Requests\Api\Pda;

use Illuminate\Foundation\Http\FormRequest;

/** `POST /api/pda/purchase-orders/{id}/cancel`: an optional reason, shown on the order. */
class PdaCancelOrderRequest extends FormRequest
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
            'reason' => ['nullable', 'string', 'max:255'],
        ];
    }

    public function reason(): ?string
    {
        $reason = $this->validated('reason');

        return is_string($reason) ? $reason : null;
    }
}
