<?php

namespace App\Http\Requests\App;

use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Inviting a portal user (name, email, role, shop) or changing a user's role and shop (role, shop) — module 4.1.
 * Route: `company.can:users.manage`. Whether the shop belongs to the business is checked by the action.
 */
class PortalUserAccessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $inviting = $this->isMethod('POST');

        return [
            'name' => $inviting ? ['required', 'string', 'max:255'] : ['prohibited'],
            'email' => $inviting ? ['required', 'string', 'email', 'max:255'] : ['prohibited'],
            'role' => ['required', Rule::enum(CompanyRole::class)],
            'branch_id' => ['nullable', 'string', 'size:26'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the person’s name.',
            'email.required' => 'Enter their email address.',
            'email.email' => 'Enter a valid email address.',
            'role.required' => 'Choose a role.',
            'branch_id.size' => 'Choose one of your open shops.',
        ];
    }

    public function role(): CompanyRole
    {
        return CompanyRole::from((string) $this->input('role'));
    }

    public function branchId(): ?string
    {
        $branch = $this->input('branch_id');

        return is_string($branch) && $branch !== '' ? $branch : null;
    }
}
