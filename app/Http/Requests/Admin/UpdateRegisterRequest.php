<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Illuminate\Foundation\Http\FormRequest;

class UpdateRegisterRequest extends FormRequest
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
        return [
            'name' => ['required', 'string', 'max:60'],
            'code' => ['required', 'string', 'regex:'.Register::CODE_PATTERN],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter a name for the till.',
            'code.regex' => 'Use two digits from 01 to 99.',
        ];
    }
}
