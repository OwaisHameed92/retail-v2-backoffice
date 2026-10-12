<?php

namespace App\Http\Requests\Admin\Partners;

use Illuminate\Foundation\Http\FormRequest;

/** Admin → Settings → Partners: a partner's name, webhook URL and whether it is active. */
class PartnerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Route middleware: can:partners.manage.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120'],
            'webhook_url' => ['nullable', 'string', 'url', 'max:500'],
            'is_active' => ['boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => 'Enter the partner’s name.',
            'webhook_url.url' => 'Enter the full address, for example https://zivaro.example/webhooks/retail-portal.',
        ];
    }
}
