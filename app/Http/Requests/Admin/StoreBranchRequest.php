<?php

namespace App\Http\Requests\Admin;

use App\Domain\Shared\Country\LocalText;
use App\Domain\Tenancy\Data\BranchDetails;
use App\Domain\Tenancy\Data\NewTenant;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;

class StoreBranchRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->company() !== null && ($this->user('admin')?->can('update', $this->company()) ?? false);
    }

    protected function prepareForValidation(): void
    {
        $this->merge(TenantRules::clean($this));
        $this->merge(AddedTillFeeRules::clean($this));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return TenantRules::branch('', $this->company()?->id) + [
            'tills' => ['required', 'integer', 'min:0', 'max:'.NewTenant::MAX_TILLS],
        ] + AddedTillFeeRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRules::messages() + [
            'code.required' => 'Enter a short branch code, for example LDS.',
            'name.required' => LocalText::places('Enter the branch name, for example Leeds.'),
            'tills.max' => 'Add up to '.NewTenant::MAX_TILLS.' tills now; you can add more later.',
        ] + AddedTillFeeRules::messages();
    }

    public function company(): ?Company
    {
        $company = $this->route('company');

        return $company instanceof Company ? $company : null;
    }

    public function details(): BranchDetails
    {
        return TenantRules::branchDetails($this);
    }

    /** P11: the admin's setup fee for the added tills (billing admins only); null = the usual fee. */
    public function tillSetupFee(): ?string
    {
        return AddedTillFeeRules::amount($this);
    }
}
