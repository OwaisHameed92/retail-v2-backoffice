<?php

namespace App\Http\Requests\Partner;

use App\Domain\Partners\Data\TenantRequestInput;
use App\Domain\Partners\Support\PartnerApiErrors;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `PUT /api/partner/v1/tenant-requests/{externalId}`: the body checked like the admin new-business form
 * (TenantRequestRules). A failure is 400 `request.invalid` with the first message, the first failing member as
 * `rejectedKey` and every message in `details.errors`.
 */
class PutTenantRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // AuthenticatePartnerKey
    }

    protected function prepareForValidation(): void
    {
        $this->replace(TenantRequestRules::clean($this->all()));
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return TenantRequestRules::rules();
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return TenantRequestRules::messages();
    }

    protected function failedValidation(Validator $validator): void
    {
        throw PartnerApiErrors::invalid($validator->errors()->toArray());
    }

    /** The externalId in the URL is checked with the body, so every problem comes back in one reply. */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if (preg_match(TenantRequestRules::EXTERNAL_ID_PATTERN, (string) $this->route('externalId')) !== 1) {
                $validator->errors()->add('externalId', 'The externalId must be 1 to 64 characters: letters, digits, dot, underscore, colon or dash.');
            }
        });
    }

    public function toInput(): TenantRequestInput
    {
        return TenantRequestInput::fromValidated($this->validated());
    }
}
