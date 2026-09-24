<?php

namespace App\Http\Requests\Admin;

use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateTenantUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        $company = $this->route('company');

        return $company instanceof Company && ($this->user('admin')?->can('update', $company) ?? false);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return ['role' => ['required', Rule::enum(CompanyRole::class)]];
    }

    public function role(): CompanyRole
    {
        return CompanyRole::from((string) $this->input('role'));
    }
}
