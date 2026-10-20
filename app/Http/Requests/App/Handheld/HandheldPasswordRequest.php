<?php

namespace App\Http\Requests\App\Handheld;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/** A new password for a handheld (PDA) user, typed twice by the manager. Route: `company.can:users.manage`. */
class HandheldPasswordRequest extends FormRequest
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
        return [
            'password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()],
        ];
    }

    public function password(): string
    {
        return (string) $this->validated('password');
    }
}
