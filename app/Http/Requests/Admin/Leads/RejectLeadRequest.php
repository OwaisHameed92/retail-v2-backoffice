<?php

namespace App\Http\Requests\Admin\Leads;

use Illuminate\Foundation\Http\FormRequest;

class RejectLeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware: can:update,lead.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'notify' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Say briefly why. Only staff see it.',
            'reason.min' => 'Say briefly why (at least 3 characters).',
        ];
    }
}
