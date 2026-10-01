<?php

namespace App\Http\Requests\App\Privacy;

use Illuminate\Foundation\Http\FormRequest;

/**
 * An erasure request (module 7.7): the owner types the word ANONYMISE to confirm (it cannot be undone) and may add
 * a note (how the customer asked, a reference). The route checks `privacy.manage`.
 */
class AnonymiseCustomerRequest extends FormRequest
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
            'confirm' => ['required', 'string', 'in:ANONYMISE,anonymise'],
            'note' => ['nullable', 'string', 'max:1000'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'confirm.required' => 'Type ANONYMISE to confirm.',
            'confirm.in' => 'Type ANONYMISE to confirm.',
        ];
    }
}
