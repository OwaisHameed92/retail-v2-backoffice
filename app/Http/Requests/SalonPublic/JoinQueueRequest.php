<?php

namespace App\Http\Requests\SalonPublic;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A phone join from the queue-join page (W3a, salon pack README §4.3): the desk's own rules, so the till rarely
 * refuses one. First name required (≤ 60), phone optional (digits / + ( ) - / spaces, ≤ 30), at most 10 services, any
 * stylist or one the page lists, note ≤ 200. The action checks the services and stylist against the page.
 */
class JoinQueueRequest extends FormRequest
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
            'firstName' => ['required', 'string', 'max:60'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^[0-9+()\-\s]*$/'],
            'services' => ['nullable', 'array', 'max:10'],
            'services.*' => ['required', 'string', 'max:64', 'distinct'],
            'stylist' => ['nullable', 'string', 'max:64'],
            'note' => ['nullable', 'string', 'max:200'],
            'src' => ['nullable', 'string', 'max:200'],
            'clientKey' => ['required', 'string', 'regex:'.BookOnlineRequest::CLIENT_KEY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'firstName.required' => 'Enter your first name.',
            'firstName.max' => 'Your first name can be at most 60 characters.',
            'phone.regex' => 'Enter a phone number using digits (you may use + ( ) - and spaces).',
            'phone.max' => 'Your phone number can be at most 30 characters.',
            'services.max' => 'Choose at most 10 services.',
            'note.max' => 'Your note can be at most 200 characters.',
        ];
    }

    /**
     * @return array{firstName: string, phone: string, services: list<string>, stylist: string|null, note: string, src: string, clientKey: string}
     */
    public function fields(): array
    {
        return [
            'firstName' => trim((string) $this->input('firstName')),
            'phone' => trim((string) $this->input('phone', '')),
            'services' => array_values(array_map(fn ($v) => (string) $v, (array) $this->input('services', []))),
            'stylist' => $this->input('stylist') === null ? null : (string) $this->input('stylist'),
            'note' => trim((string) $this->input('note', '')),
            'src' => BookOnlineRequest::src($this->input('src')),
            'clientKey' => (string) $this->input('clientKey'),
        ];
    }
}
