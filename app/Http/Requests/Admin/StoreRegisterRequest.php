<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Foundation\Http\FormRequest;

class StoreRegisterRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user('admin')?->can('update', $company) ?? false);
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
        return [
            'name' => ['nullable', 'string', 'max:60'],
            'code' => ['nullable', 'string', 'regex:'.Register::CODE_PATTERN],
            'is_main_till' => ['boolean'],
        ] + AddedTillFeeRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['code.regex' => 'Use two digits from 01 to 99.'] + AddedTillFeeRules::messages();
    }

    /** P11: the admin's setup fee for the added tills (billing admins only); null = the usual fee. */
    public function tillSetupFee(): ?string
    {
        return AddedTillFeeRules::amount($this);
    }
}
