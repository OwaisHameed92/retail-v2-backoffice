<?php

namespace App\Http\Requests\Admin\Partners;

use Illuminate\Foundation\Http\FormRequest;

/** "Reject" on a partner request: the reason the partner reads. */
class RejectPartnerRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware: can:tenants.manage.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:3', 'max:1000']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['reason.required' => 'Enter the reason the partner will see.', 'reason.min' => 'Enter the reason the partner will see.'];
    }
}
