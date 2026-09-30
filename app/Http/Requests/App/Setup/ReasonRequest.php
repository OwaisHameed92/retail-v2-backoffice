<?php

namespace App\Http\Requests\App\Setup;

use App\Domain\TillData\Enums\ReasonType;
use Illuminate\Validation\Rule;

/** Adding or editing a reason code (module 4.5). Route: `company.can:settings.manage`. */
class ReasonRequest extends CompanyWideWriteRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', Rule::enum(ReasonType::class)],
            'text' => ['required', 'string', 'max:120'],
            'position' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'is_active' => ['boolean'],
            'account_code' => ['nullable', 'string', 'max:30'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['type.required' => 'Choose when the till asks for this reason.', 'text.required' => 'Enter the reason as staff will see it.'];
    }
}
