<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Data\CompanyDetails;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user('admin')?->can('update', $company) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TenantRules::clean($this));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return TenantRules::company();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRules::messages() + ['name.required' => 'Enter the business name.'];
    }

    public function details(): CompanyDetails
    {
        return TenantRules::companyDetails($this);
    }
}
