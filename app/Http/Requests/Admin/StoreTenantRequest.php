<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user('admin')?->can('create', Company::class) ?? false;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TenantRules::clean($this, ['', 'branch_']));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return TenantRules::company() + TenantRules::branch('branch_') + [
            'status' => ['required', Rule::in([CompanyStatus::Trial->value, CompanyStatus::Active->value])],
            'tills' => ['required', 'integer', 'min:1', 'max:'.NewTenant::MAX_TILLS],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRules::messages('branch_') + [
            'name.required' => 'Enter the business name.',
            'branch_code.required' => 'Enter a short branch code, for example LDS.',
            'branch_name.required' => 'Enter the branch name, for example Leeds.',
            'tills.min' => 'A branch needs at least 1 till.',
            'tills.max' => 'Add up to '.NewTenant::MAX_TILLS.' tills now; you can add more later.',
            'owner_name.required' => 'Enter the owner’s name.',
            'owner_email.required' => 'Enter the owner’s email. We send them a link to set their password.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'branch_code' => 'branch code',
            'branch_name' => 'branch name',
            'branch_nation' => 'nation',
            'owner_email' => 'owner email',
        ];
    }

    public function toNewTenant(): NewTenant
    {
        return new NewTenant(
            company: TenantRules::companyDetails($this),
            branch: TenantRules::branchDetails($this, 'branch_'),
            tills: $this->integer('tills'),
            ownerName: (string) $this->input('owner_name'),
            ownerEmail: (string) $this->input('owner_email'),
            status: CompanyStatus::from((string) $this->input('status')),
        );
    }
}
