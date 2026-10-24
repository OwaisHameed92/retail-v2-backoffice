<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\OnlineStore\Data\CollectOrderInput;
use App\Domain\OnlineStore\Models\OnlineOrder;
use App\Domain\Shared\Country\ContactRules;
use App\Domain\Shared\Country\Country;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/**
 * The customer's own details on an online order (PlaceCollectOrder): name, mobile (on GB a UK mobile, audit OSA-26;
 * elsewhere the country's pattern), email, an optional note to the shop and the substitution choice (OSA-18),
 * and, for a delivery (OS-9), the address: first line, optional second line, town, a postcode in the country's
 * format (UK: "B24 9AB", any case and spacing) and optional instructions for the driver.
 */
final class OrderDetailsRules
{
    /** A UK mobile: 07… or +44 7…, 11 digits, spaces or dashes anywhere (OSA-26). */
    public const UK_MOBILE = '/^(?:\+44[\s-]*7|07)(?:[\s-]*\d){9}$/';

    public const NOTE_MAX = 500;

    /**
     * @return array{customer_name: string, customer_mobile: string, customer_email: string, customer_mobile_key: string, customer_note: string|null, substitutions_ok: bool}
     *
     * @throws ValidationException
     */
    public static function customer(CollectOrderInput $input): array
    {
        $data = [
            'customerName' => trim($input->customerName),
            'customerMobile' => trim($input->customerMobile),
            'customerEmail' => strtolower(trim($input->customerEmail)),
            'customerNote' => trim($input->customerNote),
        ];
        $uk = app(Country::class)->is(Country::DEFAULT);

        Validator::make($data, [
            'customerName' => ['required', 'string', 'min:2', 'max:120'],
            'customerMobile' => ['required', 'string', 'max:30', 'regex:'.($uk ? self::UK_MOBILE : app(Country::class)->phone()['pattern'])],
            'customerEmail' => ['required', 'email', 'max:255'],
            'customerNote' => ['nullable', 'string', 'max:'.self::NOTE_MAX],
        ], [
            'customerName.required' => 'Enter your name.',
            'customerMobile.required' => 'Enter your mobile number.',
            'customerMobile.regex' => $uk ? 'Enter a UK mobile number, like 07700 900123.' : 'Enter a mobile number we can reach you on.',
            'customerEmail.required' => 'Enter your email address.',
            'customerEmail.email' => 'Enter a valid email address.',
            'customerNote.max' => 'Keep the note to '.self::NOTE_MAX.' characters.',
        ])->validate();

        return [
            'customer_name' => $data['customerName'], 'customer_mobile' => $data['customerMobile'], 'customer_email' => $data['customerEmail'],
            'customer_mobile_key' => OnlineOrder::mobileKey($data['customerMobile']),
            'customer_note' => $data['customerNote'] === '' ? null : $data['customerNote'],
            'substitutions_ok' => $input->substitutionsOk,
        ];
    }

    /**
     * @return array{delivery_line1: string, delivery_line2: string|null, delivery_town: string, delivery_postcode: string, delivery_notes: string|null}
     *
     * @throws ValidationException
     */
    public static function address(CollectOrderInput $input): array
    {
        $data = [
            'deliveryLine1' => trim($input->deliveryLine1),
            'deliveryLine2' => trim($input->deliveryLine2),
            'deliveryTown' => trim($input->deliveryTown),
            'deliveryPostcode' => trim($input->deliveryPostcode),
            'deliveryNotes' => trim($input->deliveryNotes),
        ];

        Validator::make($data, [
            'deliveryLine1' => ['required', 'string', 'min:3', 'max:120'],
            'deliveryLine2' => ['nullable', 'string', 'max:120'],
            'deliveryTown' => ['required', 'string', 'min:2', 'max:80'],
            'deliveryPostcode' => ['required', 'string', 'max:10'],
            'deliveryNotes' => ['nullable', 'string', 'max:255'],
        ], [
            'deliveryLine1.required' => 'Enter the first line of your address.',
            'deliveryLine1.min' => 'Enter the first line of your address.',
            'deliveryTown.required' => 'Enter your town or city.',
            'deliveryTown.min' => 'Enter your town or city.',
            'deliveryPostcode.required' => 'Enter your postcode.',
            'deliveryNotes.max' => 'Keep the delivery instructions to 255 characters.',
        ])->validate();

        $postcode = DeliveryArea::postcode($data['deliveryPostcode'])
            ?? throw ValidationException::withMessages(['deliveryPostcode' => ContactRules::postcodeMessage('Enter a UK postcode like LS1 6AB.')]);

        return [
            'delivery_line1' => $data['deliveryLine1'],
            'delivery_line2' => $data['deliveryLine2'] === '' ? null : $data['deliveryLine2'],
            'delivery_town' => $data['deliveryTown'],
            'delivery_postcode' => $postcode,
            'delivery_notes' => $data['deliveryNotes'] === '' ? null : $data['deliveryNotes'],
        ];
    }
}
