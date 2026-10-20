<?php

namespace App\Http\Requests\Api\Pda;

use App\Domain\Shared\Rules\ValidUlid;
use Illuminate\Foundation\Http\FormRequest;

/**
 * `POST /api/pda/login` (docs/pda-api.md): email + password; `tenant_id` only when one email opens accounts in two
 * businesses (409 TENANT_REQUIRED); `device_name` (optional) shows on the portal's handheld users screen.
 */
class PdaLoginRequest extends FormRequest
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
            'email' => ['required', 'string', 'max:255'],
            'password' => ['required', 'string', 'max:255'],
            'tenant_id' => ['nullable', 'string', new ValidUlid],
            'device_name' => ['nullable', 'string', 'max:120'],
        ];
    }
}
