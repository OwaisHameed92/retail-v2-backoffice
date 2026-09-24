<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspend and revoke a licence: a reason is required and kept in the activity log.
 * Authorised by the `can:licences.manage` route middleware.
 */
class LicenceReasonRequest extends FormRequest
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
        return ['reason' => ['required', 'string', 'min:3', 'max:500']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'reason.required' => 'Enter a reason. It is kept in the activity log.',
            'reason.min' => 'Enter a reason. It is kept in the activity log.',
        ];
    }

    public function reason(): string
    {
        return trim((string) $this->input('reason'));
    }
}
