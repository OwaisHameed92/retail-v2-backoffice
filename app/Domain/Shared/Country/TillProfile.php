<?php

namespace App\Domain\Shared\Country;

use App\Domain\Shared\Support\AppVersion;

/**
 * The till-facing part of the country profile (`till.*` in config/country.php; Pak POS pack 2026-10-07). Each value is
 * missing on GB, so the UK's licence replies, tokens, pulls, forms and version checks stay exactly as they were.
 *
 *     TillProfile::licenceCountry();          // null on GB, "PK" on PK (contract §17.18)
 *     TillProfile::compareAppVersion('1.0.0', '0.1.40'); // PK: 1 (Pak POS 1.x counts as SSPOS 0.1.53)
 */
final class TillProfile
{
    /** The whole digits a product price may have where the profile says nothing (the GB forms' rule). */
    public const DEFAULT_PRICE_DIGITS = 8;

    /** ISO 3166-1 alpha-2 for the licence token, activate and validate replies; null = the field is not sent (GB). */
    public static function licenceCountry(): ?string
    {
        $code = self::value('licenceCountry');

        return is_string($code) && preg_match('/^[A-Z]{2}$/', $code) === 1 ? $code : null;
    }

    /**
     * `['country' => 'PK']` for a reply where the profile names a licence country, else nothing (GB).
     *
     * @return array<string, string>
     */
    public static function countryMember(): array
    {
        $code = self::licenceCountry();

        return $code === null ? [] : ['country' => $code];
    }

    /** The till app's name for portal text: "SSPOS" (GB), "Pak POS" (PK). */
    public static function appName(): string
    {
        $name = self::value('appName');

        return is_string($name) && $name !== '' ? $name : 'SSPOS';
    }

    /** The Branch `nation` every shop of this country carries ("Pakistan"), null where the forms offer nations (GB). */
    public static function branchNation(): ?string
    {
        $nation = self::value('branchNation');

        return is_string($nation) && $nation !== '' ? $nation : null;
    }

    /**
     * The till age rules the portal's pickers offer (enum values), null = every rule (GB). Stored values are kept.
     *
     * @return list<string>|null
     */
    public static function ageRules(): ?array
    {
        $rules = self::value('ageRules');

        return is_array($rules) && $rules !== [] ? array_values(array_map('strval', $rules)) : null;
    }

    /** Whether the pickers offer this age rule (always on GB). */
    public static function offersAgeRule(string $rule): bool
    {
        $rules = self::ageRules();

        return $rules === null || in_array($rule, $rules, true);
    }

    /** Whole digits of a product price: 8 (GB forms, as always), 7 on PK (up to 9,999,999.99). */
    public static function priceDigits(): int
    {
        $digits = self::value('priceDigits');

        return is_int($digits) && $digits > 0 ? $digits : self::DEFAULT_PRICE_DIGITS;
    }

    /** The validation rule for a product price (2 decimals): GB `regex:/^\d{1,8}(\.\d{1,2})?$/` exactly as before. */
    public static function priceRule(): string
    {
        return 'regex:/^\d{1,'.self::priceDigits().'}(\.\d{1,2})?$/';
    }

    /** The highest product price: "99999999.99" (GB forms), "9999999.99" (PK). */
    public static function priceMax(): string
    {
        return str_repeat('9', self::priceDigits()).'.99';
    }

    /**
     * Compares a till's app version with a gate (a minimum version, a blocked version), like AppVersion::compare.
     * GB: plain semver, unchanged. Where the profile names an SSPOS baseline (PK), the till app is its own product line
     * from 1.0.0 (Pak POS), never compared number for number with SSPOS 3's 0.1.x: against a gate written in 0.x
     * numbers a version of 1.0 or later counts as the baseline it was cut from (0.1.53). Two versions of one line
     * (both 0.x, or both 1.x and later) compare as they are.
     */
    public static function compareAppVersion(string $version, string $gate): int
    {
        return AppVersion::compare(self::onGateLine($version, $gate), $gate);
    }

    /**
     * The oldest supported till version as configured (`licence.api.minimum_app_version`), on the till's own product
     * line: GB as configured. On PK a value in SSPOS 3's 0.x numbers (the default 0.1.0) means "any", so Pak POS tills
     * get the profile's first version (1.0.0) instead; a value of 1.0 or later is a Pak POS version and is kept.
     * Block lists (`sync.blocked_app_versions`) need nothing: a 0.1.x pattern never matches a 1.x version.
     */
    public static function minimumAppVersion(string $configured): string
    {
        $first = self::value('firstAppVersion');

        if (self::baseline() === null || ! is_string($first) || ! AppVersion::isValid($first) || self::major($configured) >= 1) {
            return $configured;
        }

        return $first;
    }

    /**
     * Shared with the front end only where the profile sets them (PK): `ageRules`, `priceDigits`.
     *
     * @return array<string, mixed>
     */
    public static function frontend(Country $country): array
    {
        $rules = $country->till('ageRules');
        $digits = $country->till('priceDigits');

        return [
            ...(is_array($rules) && $rules !== [] ? ['ageRules' => array_values($rules)] : []),
            ...(is_int($digits) ? ['priceDigits' => $digits] : []),
        ];
    }

    /** The version as the gate's product line sees it. */
    private static function onGateLine(string $version, string $gate): string
    {
        $baseline = self::baseline();

        if ($baseline === null || self::major($version) < 1 || self::major(ltrim(trim($gate), 'v')) !== 0) {
            return $version;
        }

        return $baseline;
    }

    private static function baseline(): ?string
    {
        $baseline = self::value('ssposBaseline');

        return is_string($baseline) && AppVersion::isValid($baseline) ? $baseline : null;
    }

    private static function major(string $version): int
    {
        return preg_match('/^v?(\d+)/', trim($version), $m) === 1 ? (int) $m[1] : -1;
    }

    private static function value(string $key): mixed
    {
        return app(Country::class)->till($key);
    }
}
