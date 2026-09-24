<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class ImpersonateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user('admin')?->can('impersonate', $company) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['user_id' => ['required', 'integer']];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['user_id.required' => 'Choose the user to log in as.'];
    }
}
