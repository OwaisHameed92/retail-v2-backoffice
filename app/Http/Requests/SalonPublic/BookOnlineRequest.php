<?php

namespace App\Http\Requests\SalonPublic;

use Illuminate\Foundation\Http\FormRequest;

/**
 * A client's booking request from the public booking page (W3a, salon pack README §5, §11). Anyone may send one
 * (rate limited); the action checks the services, stylist and time against the published page. Marketing ticks are
 * explicit booleans, never assumed; the terms are a separate tick. No health data is asked for.
 */
class BookOnlineRequest extends FormRequest
{
    public const PHONE = '/^[0-9+()\-\s]{6,30}$/';

    public const CLIENT_KEY = '/^[A-Za-z0-9-]{8,64}$/';

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
            'options' => ['required', 'array', 'min:1', 'max:10'],
            'options.*' => ['required', 'string', 'max:64', 'distinct'],
            'addOns' => ['nullable', 'array', 'max:10'],
            'addOns.*' => ['required', 'string', 'max:64', 'distinct'],
            'stylist' => ['required', 'string', 'max:64'],
            'date' => ['required', 'date_format:Y-m-d'],
            'time' => ['required', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],
            'firstName' => ['required', 'string', 'max:60'],
            'lastName' => ['required', 'string', 'max:60'],
            'phone' => ['required', 'string', 'regex:'.self::PHONE],
            'email' => ['required', 'string', 'email', 'max:120'],
            'notes' => ['nullable', 'string', 'max:450'],
            'answers' => ['nullable', 'array', 'max:50'],
            'answers.*' => ['nullable', 'string', 'max:500'],
            'patchTest' => ['nullable', 'in:yes,no'],
            'terms' => ['boolean'],
            'marketingSms' => ['boolean'],
            'marketingEmail' => ['boolean'],
            'marketingWhatsApp' => ['boolean'],
            'src' => ['nullable', 'string', 'max:200'],
            'clientKey' => ['required', 'string', 'regex:'.self::CLIENT_KEY],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'options.required' => 'Choose a service.',
            'stylist.required' => 'Choose a stylist, or any stylist.',
            'date.required' => 'Choose a day.',
            'time.required' => 'Choose a time.',
            'firstName.required' => 'Enter your first name.',
            'lastName.required' => 'Enter your last name.',
            'phone.required' => 'Enter your phone number.',
            'phone.regex' => 'Enter a phone number using digits (you may use + ( ) - and spaces).',
            'email.required' => 'Enter your e-mail address.',
            'email.email' => 'Enter a valid e-mail address.',
        ];
    }

    /**
     * @return array{options: list<string>, addOns: list<string>, stylist: string, date: string, time: string, firstName: string, lastName: string, phone: string, email: string, notes: string, answers: list<string>, patchTest: string|null, terms: bool, marketingSms: bool, marketingEmail: bool, marketingWhatsApp: bool, src: string, clientKey: string}
     */
    public function fields(): array
    {
        $strings = fn (string $key): array => array_values(array_map(fn ($v) => (string) $v, (array) $this->input($key, [])));

        return [
            'options' => $strings('options'),
            'addOns' => $strings('addOns'),
            'stylist' => (string) $this->input('stylist'),
            'date' => (string) $this->input('date'),
            'time' => (string) $this->input('time'),
            'firstName' => trim((string) $this->input('firstName')),
            'lastName' => trim((string) $this->input('lastName')),
            'phone' => trim((string) $this->input('phone')),
            'email' => trim((string) $this->input('email')),
            'notes' => trim((string) $this->input('notes', '')),
            'answers' => $strings('answers'),
            'patchTest' => $this->input('patchTest') === null ? null : (string) $this->input('patchTest'),
            'terms' => $this->boolean('terms'),
            'marketingSms' => $this->boolean('marketingSms'),
            'marketingEmail' => $this->boolean('marketingEmail'),
            'marketingWhatsApp' => $this->boolean('marketingWhatsApp'),
            'src' => self::src($this->input('src')),
            'clientKey' => (string) $this->input('clientKey'),
        ];
    }

    /** `?src=` as a short plain word (google, instagram, widget…); anything else is dropped. */
    public static function src(mixed $value): string
    {
        $src = strtolower(trim((string) $value));

        return preg_match('/^[a-z0-9_-]{1,30}$/', $src) === 1 ? $src : '';
    }
}
