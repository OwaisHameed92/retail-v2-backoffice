<?php

namespace App\Http\Requests\App\Handheld;

use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

/**
 * Add (POST, with the password the manager chose) or edit (PUT) a handheld (PDA) user. Route:
 * `company.can:users.manage`. The login email is unique within this business; the shop must be one of its open
 * shops (checked by the action).
 */
class HandheldUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'name' => is_string($this->input('name')) ? trim($this->input('name')) : $this->input('name'),
            'email' => is_string($this->input('email')) ? mb_strtolower(trim($this->input('email'))) : $this->input('email'),
            'can_change_prices' => $this->boolean('can_change_prices'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $creating = $this->isMethod('post');
        $id = $this->route('handheld');

        return [
            'name' => ['required', 'string', 'max:120'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('handheld_users', 'email')
                ->where('company_id', app(CurrentCompany::class)->id())
                ->ignore(is_string($id) ? $id : null)],
            'branch_id' => ['required', 'string', 'size:26'],
            'can_change_prices' => ['required', 'boolean'],
            ...($creating ? ['password' => ['required', 'string', 'max:255', 'confirmed', Password::defaults()]] : []),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'email.unique' => 'Another handheld user of this business already signs in with this email.',
            'branch_id.required' => 'Choose the shop this handheld works in.',
            'branch_id.size' => 'Choose one of your open shops.',
        ];
    }

    /**
     * @return array{name: string, email: string, branch_id: string, password: string, can_change_prices: bool}
     */
    public function handheld(): array
    {
        /** @var array{name: string, email: string, branch_id: string, password?: string, can_change_prices: bool} $data */
        $data = $this->validated();

        return [...$data, 'password' => $data['password'] ?? ''];
    }
}
