<?php

namespace App\Http\Requests\App\Shops;

use App\Domain\Shops\Data\BusinessDetails;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Requests\Admin\TenantRules;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The business's details from Shops and tills (module 4.7). The route checks `business.manage` (owner); a one-shop
 * user never changes what every shop prints. Same formats as the admin tenant form (TenantRules).
 */
class BusinessDetailsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return app(CurrentCompany::class)->restrictedBranchId() === null;
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
        return array_intersect_key(TenantRules::company(), array_flip(BusinessDetails::COLUMNS));
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRules::messages();
    }

    public function details(): BusinessDetails
    {
        return BusinessDetails::fromArray($this->validated());
    }
}
