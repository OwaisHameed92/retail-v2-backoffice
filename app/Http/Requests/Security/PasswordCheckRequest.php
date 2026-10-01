<?php

namespace App\Http\Requests\Security;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/** The signed-in person's current password, before a security change (admin or portal guard by URL). */
class PasswordCheckRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $guard = $this->is('admin', 'admin/*') ? 'admin' : 'web';

        return [
            'password' => ['required', 'string', 'current_password:'.$guard],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'password.required' => 'Enter your password.',
            'password.current_password' => 'That password is not right.',
        ];
    }
}
