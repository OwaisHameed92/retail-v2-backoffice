<?php

namespace App\Domain\Shared\Country;

use Illuminate\Container\Container;

/**
 * UK-only words in text shown to people (Pakistan plan P6: mail, PDFs, messages, help text), from the country profile.
 * Each helper takes the GB text and returns it unchanged on GB (byte-identical), so a UK call site reads as before.
 *
 *     LocalText::domains('name@yourshop.co.uk');   // GB as given; PK "name@yourshop.pk"
 *     LocalText::phone('0113 496 0123');           // GB as given; PK the profile's example "0300 1234567"
 *     LocalText::region();                         // "UK" (GB), "Pakistan"
 *     LocalText::registration('01234567');         // "Registered in England and Wales, company no. 01234567"
 *     LocalText::places('for example Leeds');      // GB as given; PK "for example Lahore" (phase P9)
 *     LocalText::currency('in pounds, like 1.25'); // GB as given; PK "in rupees, like 1.25" (phase P9)
 *     LocalText::ukOnly('Challenge 25: usually 25.', 'Usually 25.'); // GB the first, elsewhere the second (P9)
 */
final class LocalText
{
    /** Example web addresses and emails: ".co.uk" becomes the country's own domain (".pk") off GB. */
    public static function domains(string $gb): string
    {
        $country = self::country();

        return $country === null || self::isUk($country) ? $gb : str_replace('.co.uk', '.'.strtolower($country->code()), $gb);
    }

    /** An example phone number: unchanged on GB, the profile's example elsewhere. */
    public static function phone(string $gb): string
    {
        $country = self::country();

        return $country === null || self::isUk($country) ? $gb : $country->phone()['example'];
    }

    /** Whose time or dates a label means: "UK" on GB (as always), the country name elsewhere ("Pakistan"). */
    public static function region(): string
    {
        $country = self::country();

        return $country === null || self::isUk($country) ? 'UK' : $country->name();
    }

    /**
     * The registration part of the seller line on our own invoices: "Registered in England and Wales, company no.
     * 01234567" on GB (as always); elsewhere the profile's place and company id label ("Registered in Pakistan, SECP
     * registration number 0123456"). `billing.seller.registered_in` (BILLING_SELLER_REGISTERED_IN) overrides the place.
     */
    public static function registration(string $companyNumber): string
    {
        $country = self::country() ?? Country::fromConfig();
        $place = trim((string) config('billing.seller.registered_in')) ?: $country->registeredIn();
        $label = self::isUk($country) ? 'company no.' : ($country->sellerIdLabel('companyNumber') ?? $country->taxIdFor('company_number')['label'] ?? 'company no.');

        return "Registered in {$place}, {$label} {$companyNumber}";
    }

    /** UK sample places ("Leeds", "LDS") swapped for the profile's own (`samplePlaces`); GB text unchanged. */
    public static function places(string $gb): string
    {
        $country = self::country();

        return $country === null || self::isUk($country) || $country->samplePlaces() === [] ? $gb : strtr($gb, $country->samplePlaces());
    }

    /** "pounds" in money wording ("Enter an amount in pounds") becomes the profile's currency name off GB ("rupees"). */
    public static function currency(string $gb): string
    {
        $country = self::country();

        return $country === null || self::isUk($country) ? $gb : (string) preg_replace('/\bpounds\b/', $country->currencyName(), $gb);
    }

    /** The UK text on GB (as always), the neutral or local text elsewhere. */
    public static function ukOnly(string $gb, string $other): string
    {
        $country = self::country();

        return $country === null || self::isUk($country) ? $gb : $other;
    }

    private static function country(): ?Country
    {
        $container = Container::getInstance();

        return $container->bound(Country::class) ? $container->make(Country::class) : null;
    }

    private static function isUk(Country $country): bool
    {
        return $country->is(Country::DEFAULT);
    }
}
