<?php

namespace App\Domain\Shared\Country;

use Illuminate\Container\Container;

/**
 * Postcode, town and phone validation per country profile (Pakistan plan P4). On GB every method returns what the
 * form passes in, unchanged: each UK form keeps its own pattern, required-ness and message byte for byte. Elsewhere
 * the profile decides: its postcode pattern, a postcode that is optional when the profile says so (a profile never
 * makes a postcode newly required), the town required with an address, and its phone pattern and example.
 *
 *     'postcode' => ContactRules::postcode(['nullable', 'string', 'max:10', 'regex:'.self::POSTCODE_PATTERN]),
 *     'postcode.regex' => ContactRules::postcodeMessage('Enter a UK postcode like LS1 6AB.'),
 */
final class ContactRules
{
    /** The UK examples in today's phone messages, swapped for the profile's example elsewhere. */
    private const UK_PHONE_EXAMPLES = ['0113 496 0000', '07700 900123'];

    /**
     * A postcode field's rules: the form's own on GB; elsewhere the profile's pattern, optional unless both the form
     * and the profile require one.
     *
     * @param  list<string>  $gb
     * @return list<string>
     */
    public static function postcode(array $gb): array
    {
        $country = self::country();
        if (self::isUk($country)) {
            return $gb;
        }

        $required = in_array('required', $gb, true) && $country->address()['postcodeRequired'];

        return [$required ? 'required' : 'nullable', 'string', 'max:10', 'regex:'.$country->address()['postcodePattern']];
    }

    /** The postcode format message: the form's own on GB, "Enter a postal code like 54000." elsewhere. */
    public static function postcodeMessage(string $gb): string
    {
        $country = self::country();
        if (self::isUk($country)) {
            return $gb;
        }

        $address = $country->address();

        return 'Enter a '.mb_strtolower($address['postcodeLabel']).' like '.$address['postcodeExample'].'.';
    }

    /**
     * The format message for a form whose GB postcode has no pattern (supplier, trial): none on GB.
     *
     * @return array<string, string>
     */
    public static function postcodeFormatMessages(string $field = 'postcode'): array
    {
        return self::isUk(self::country()) ? [] : [$field.'.regex' => self::postcodeMessage('')];
    }

    /**
     * A town field's rules: the form's own on GB; where the profile says `cityRequired`, an optional town becomes
     * required as soon as one of `$with` (the address or postcode fields) is filled in.
     *
     * @param  list<string>  $gb
     * @param  list<string>  $with
     * @return list<string>
     */
    public static function town(array $gb, array $with): array
    {
        $country = self::country();
        if (self::isUk($country) || ! $country->address()['cityRequired'] || in_array('required', $gb, true)) {
            return $gb;
        }

        return [...$gb, 'required_with:'.implode(',', $with)];
    }

    /**
     * The message for the rule `town()` adds, keyed by the town field; none on GB.
     *
     * @return array<string, string>
     */
    public static function townMessages(string $field = 'town'): array
    {
        $country = self::country();

        return self::isUk($country) || ! $country->address()['cityRequired']
            ? []
            : [$field.'.required_with' => 'Enter the city for this address.'];
    }

    /** A phone pattern: the form's own on GB, the profile's elsewhere (PK: "0300 1234567", "+92 300 1234567"). */
    public static function phonePattern(string $gb): string
    {
        $country = self::country();

        return self::isUk($country) ? $gb : $country->phone()['pattern'];
    }

    /** Phone message text: unchanged on GB; elsewhere the UK example numbers become the profile's example. */
    public static function phoneText(string $gb): string
    {
        $country = self::country();

        return self::isUk($country) ? $gb : str_replace(self::UK_PHONE_EXAMPLES, $country->phone()['example'], $gb);
    }

    /** The profile's calling code without "+": "44" (GB), "92" (PK). GB outside a booted app (plain unit tests). */
    public static function dialCode(): string
    {
        $container = Container::getInstance();

        return $container->bound(Country::class) ? $container->make(Country::class)->phone()['dialCode'] : '44';
    }

    private static function country(): Country
    {
        return app(Country::class);
    }

    private static function isUk(Country $country): bool
    {
        return $country->is(Country::DEFAULT);
    }
}
