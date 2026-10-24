<?php

namespace App\Http\Requests\Store;

use App\Domain\OnlineStore\Support\CheckoutChallenge;
use App\Http\Support\Store\StoreContext;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * The public store's checkout (OS-2, OS-9): shop, collect or delivery, slot start, the customer's details, the
 * delivery address, the way to pay, a note and the substitution choice. The lines are the session basket, never the
 * request. `website` is a honeypot: hidden from people, so only a bot fills it in, and then nothing is placed; the
 * checkout challenge (CheckoutChallenge, audit OSA-1) must be answered too. The detailed checks (shop, slot,
 * postcode, fee, ways to pay, mobile pattern) are PlaceCollectOrder's.
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
            'fulfilment' => ['nullable', 'string', 'in:collection,delivery'],
            'collectFrom' => ['required', 'string', 'max:40'],
            'customerName' => ['required', 'string', 'max:120'],
            'customerMobile' => ['required', 'string', 'max:30'],
            'customerEmail' => ['required', 'string', 'max:255'],
            'paymentMethod' => ['nullable', 'string', 'max:20'],
            'deliveryLine1' => ['nullable', 'string', 'max:120'],
            'deliveryLine2' => ['nullable', 'string', 'max:120'],
            'deliveryTown' => ['nullable', 'string', 'max:80'],
            'deliveryPostcode' => ['nullable', 'string', 'max:10'],
            'deliveryNotes' => ['nullable', 'string', 'max:255'],
            'customerNote' => ['nullable', 'string', 'max:500'],
            'substitutionsOk' => ['nullable', 'boolean'],
            'challenge' => ['nullable', 'string', 'max:300'],
            'challengeAnswer' => ['nullable', 'string', 'max:12'],
            self::HONEYPOT => ['prohibited'],
        ];
    }

    /**
     * The checkout challenge (audit OSA-1), once the fields are read.
     *
     * @return list<Closure(Validator): void>
     */
    public function after(): array
    {
        return [function (Validator $validator): void {
            $context = $this->attributes->get(StoreContext::class);
            $storeId = $context instanceof StoreContext ? $context->store->id : '';

            if (! $validator->errors()->has(self::HONEYPOT)
                && ! CheckoutChallenge::verify($storeId, (string) $this->input('challenge'), (string) $this->input('challengeAnswer'))) {
                $validator->errors()->add('challenge', 'We could not check this order came from a person. Wait a moment, then place it again.');
            }
        }];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'shopId.required' => 'Choose the shop you will collect from.',
            'shopId.regex' => 'Choose one of the shops listed.',
            'fulfilment.in' => 'Choose click & collect or delivery.',
            'collectFrom.required' => 'Choose a time.',
            'customerName.required' => 'Enter your name.',
            'customerMobile.required' => 'Enter your mobile number.',
            'customerEmail.required' => 'Enter your email address.',
            'customerNote.max' => 'Keep the note to 500 characters.',
            self::HONEYPOT.'.prohibited' => 'We could not place the order. Refresh the page and try again.',
        ];
    }

    /**
     * The order details for PlaceBasketOrder (camelCase, as CollectOrderInput reads them).
     *
     * @return array<string, mixed>
     */
    public function details(): array
    {
        $text = fn (string $key): string => (string) $this->validated($key);

        return [
            'fulfilment' => $text('fulfilment') ?: 'collection',
            'collectFrom' => $text('collectFrom'),
            'customerName' => $text('customerName'),
            'customerMobile' => $text('customerMobile'),
            'customerEmail' => $text('customerEmail'),
            'paymentMethod' => $text('paymentMethod'),
            'deliveryLine1' => $text('deliveryLine1'),
            'deliveryLine2' => $text('deliveryLine2'),
            'deliveryTown' => $text('deliveryTown'),
            'deliveryPostcode' => $text('deliveryPostcode'),
            'deliveryNotes' => $text('deliveryNotes'),
            'customerNote' => $text('customerNote'),
            'substitutionsOk' => $this->boolean('substitutionsOk'),
        ];
    }
}
