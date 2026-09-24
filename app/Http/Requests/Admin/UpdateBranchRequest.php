<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class UpdateBranchRequest extends FormRequest
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
        $company = $this->route('company');
        $branch = $this->route('branch');

        return TenantRules::branch(
            '',
            $company instanceof Company ? $company->id : null,
            is_string($branch) ? $branch : null,
        );
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRules::messages() + [
            'code.required' => 'Enter a short branch code, for example LDS.',
            'name.required' => 'Enter the branch name.',
        ];
    }

    public function details(): BranchDetails
    {
        return TenantRules::branchDetails($this);
    }
}
