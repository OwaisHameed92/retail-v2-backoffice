<?php

namespace App\Http\Requests\Store;

use Illuminate\Foundation\Http\FormRequest;

/**
 * The public store's checkout (OS-2): collection shop, slot start and the customer's details. The lines are the
 * session basket, never the request. `website` is a honeypot: hidden from people, so only a bot fills it in, and
 * then nothing is placed. The detailed checks (shop, slot, mobile pattern) are PlaceCollectOrder's.
 */
class PlaceStoreOrderRequest extends FormRequest
{
    public const HONEYPOT = 'website';

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
            'shopId' => ['required', 'string', 'regex:/^[0-9A-Za-z]{26}$/'],
            'collectFrom' => ['required', 'string', 'max:40'],
            'customerName' => ['required', 'string', 'max:120'],
            'customerMobile' => ['required', 'string', 'max:30'],
            'customerEmail' => ['required', 'string', 'max:255'],
            self::HONEYPOT => ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shopId.required' => 'Choose the shop you will collect from.',
            'shopId.regex' => 'Choose one of the shops listed.',
            'collectFrom.required' => 'Choose a collection time.',
            'customerName.required' => 'Enter your name.',
            'customerMobile.required' => 'Enter your mobile number.',
            'customerEmail.required' => 'Enter your email address.',
            self::HONEYPOT.'.prohibited' => 'We could not place the order. Refresh the page and try again.',
        ];
    }

    /**
     * @return array{collectFrom: string, customerName: string, customerMobile: string, customerEmail: string}
     */
    public function customer(): array
    {
        return [
            'collectFrom' => (string) $this->validated('collectFrom'),
            'customerName' => (string) $this->validated('customerName'),
            'customerMobile' => (string) $this->validated('customerMobile'),
            'customerEmail' => (string) $this->validated('customerEmail'),
        ];
    }
}
