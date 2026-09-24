<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Suspend and cancel both need a reason, shown to staff and kept in the audit log.
 */
class TenantStatusReasonRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user('admin')?->can('update', $company) ?? false);
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
