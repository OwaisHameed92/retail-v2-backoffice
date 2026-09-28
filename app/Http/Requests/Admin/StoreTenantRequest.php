<?php

namespace App\Http\Requests\Admin;

use App\Domain\Billing\Data\UpfrontPayment;
use App\Domain\Licensing\Data\BranchLicenceSettings;
use App\Domain\Licensing\Signing\Sspos\TokenKind;
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
        $this->merge(TenantRules::clean($this, ['', 'branch_']) + UpfrontPaymentRules::clean($this));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Own rules first: `owner_name` is the owner login here (also the keys' owner's name, cut to 80).
        return [
            'owner_name' => ['required', 'string', 'max:120'],
        ] + TenantRules::company() + TenantRules::branch('branch_') + [
            'status' => ['required', Rule::in([CompanyStatus::Trial->value, CompanyStatus::Active->value])],
            'tills' => ['required', 'integer', 'min:1', 'max:'.NewTenant::MAX_TILLS],
            'owner_name' => ['required', 'string', 'max:120'],
            'owner_email' => ['required', 'string', 'email', 'max:255'],
            // Module 1.3: plan for the new tills; blank = the portal default plan.
            'plan_id' => ['nullable', 'string', Rule::exists('plans', 'id')->where('is_active', true)->whereNull('deleted_at')],
        ] + array_merge(LicenceFormRules::branch(false), [
            // Module 1.11: the licence form is optional here (blank = plan trial and features, one branch).
            'kind' => ['nullable', Rule::enum(TokenKind::class)],
            'max_registers' => ['nullable', 'integer', 'min:1', 'max:'.BranchLicenceSettings::MAX_REGISTERS, 'gte:tills'],
        ]) + LicenceFormRules::limits() + UpfrontPaymentRules::rules();
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
            'plan_id.exists' => 'Choose an active plan.',
            'max_registers.gte' => 'Allow at least as many tills as you add now.',
        ] + LicenceFormRules::messages() + UpfrontPaymentRules::messages();
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

    /** Module 1.13: the upfront payment staff took (billing admins only), or null. */
    public function upfront(): ?UpfrontPayment
    {
        return UpfrontPaymentRules::payment($this);
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
            planId: $this->filled('plan_id') ? (string) $this->input('plan_id') : null,
            licence: $this->filled('kind') ? LicenceFormRules::settings($this, $this->integer('tills')) : null,
            multiBranch: $this->boolean('multi_branch'),
            maxBranches: $this->filled('max_branches') ? $this->integer('max_branches') : 1,
        );
    }
}
