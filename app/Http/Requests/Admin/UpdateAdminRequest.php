<?php

namespace App\Http\Requests\Admin;

use App\Domain\Admin\Enums\AdminRole;
use App\Domain\Admin\Models\Admin;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateAdminRequest extends FormRequest
{
    public function authorize(): bool
    {
        $target = $this->route('admin');

        return $target instanceof Admin && ($this->user('admin')?->can('update', $target) ?? false);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        /** @var Admin $target */
        $target = $this->route('admin');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255', Rule::unique('admins', 'email')->ignore($target->getKey())],
            'role' => ['required', Rule::enum(AdminRole::class)],
            'password' => ['nullable', 'string', 'confirmed', Password::min(12)],
        ];
    }

    public function role(): AdminRole
    {
        return AdminRole::from($this->string('role')->value());
    }

    public function newPassword(): ?string
    {
        $password = $this->string('password')->value();

        return $password === '' ? null : $password;
    }
}
