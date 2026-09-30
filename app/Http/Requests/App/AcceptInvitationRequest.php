<?php

namespace App\Http\Requests\App;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

/**
 * Accepting a portal invitation (module 4.1). A new account needs a name and a password; someone who already has an
 * account is signed in and sends nothing. The token and signature are checked by the controller and the action.
 */
class AcceptInvitationRequest extends FormRequest
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
        if ($this->user() instanceof User) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', Password::defaults()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['name.required' => 'Enter your name.', 'password.required' => 'Choose a password.'];
    }
}
