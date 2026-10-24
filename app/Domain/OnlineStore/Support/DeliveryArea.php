<?php

namespace App\Domain\OnlineStore\Support;

use App\Domain\Shared\Country\Country;

/**
 * Where a shop delivers (OS-9): a list of postcode districts ("B23"), sectors ("B24 9") or full postcodes
 * ("B24 9AB"). There is no postcode → map source in the codebase, so areas are postcode based only (no radius).
 *
 * On GB a postcode is written "OUT IN" (outward code, a space, the 3-character inward code) and an area matches when
 * its outward code is the postcode's and the postcode's inward code starts with the rest of it ("B2" never matches
 * "B23 1AA"). Elsewhere (a country profile with its own postal codes) the area is a prefix of the code.
 */
final class DeliveryArea
{
    /** A UK postcode, any case and spacing (the same as the portal's other UK postcode fields). */
    public const UK_POSTCODE = '/^[A-Z]{1,2}[0-9][A-Z0-9]? ?[0-9][A-Z]{2}$/';

    /** A UK district, sector or full postcode as an area. */
    private const UK_AREA = '/^[A-Z]{1,2}[0-9][A-Z0-9]?( [0-9]([A-Z]{2})?)?$/';

    public const MAX_AREAS = 200;

    /** The customer's postcode written the standard way ("b249ab" → "B24 9AB"), or null when it is not one. */
    public static function postcode(string $value): ?string
    {
        $compact = preg_replace('/\s+/', '', mb_strtoupper(trim($value))) ?? '';

        if (! self::uk()) {
            $pattern = app(Country::class)->address()['postcodePattern'];

            return $compact !== '' && preg_match($pattern, $compact) === 1 ? $compact : null;
        }

        if (preg_match(self::UK_POSTCODE, $compact) !== 1) {
            return null;
        }

        return substr($compact, 0, -3).' '.substr($compact, -3);
    }

    /** An area as stored ("b24  9" → "B24 9"), or null when it is not a district, sector or postcode. */
    public static function area(string $value): ?string
    {
        $area = preg_replace('/\s+/', ' ', mb_strtoupper(trim($value))) ?? '';

        if (! self::uk()) {
            return preg_match('/^[A-Z0-9][A-Z0-9 -]{0,9}$/', $area) === 1 ? $area : null;
        }

        if (! str_contains($area, ' ') && preg_match(self::UK_POSTCODE, $area) === 1) {
            $area = substr($area, 0, -3).' '.substr($area, -3);
        }

        return preg_match(self::UK_AREA, $area) === 1 ? $area : null;
    }

    /**
     * Whether a postcode (any spelling) is in one of the areas.
     *
     * @param  list<string>|null  $areas
     */
    public static function covers(?array $areas, string $postcode): bool
    {
        $code = self::postcode($postcode);

        if ($code === null) {
            return false;
        }

        foreach ($areas ?? [] as $entry) {
            $area = self::area((string) $entry);

            if ($area !== null && self::matches($area, $code)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The areas typed in the portal (a list, or text split on commas, semicolons and new lines) cleaned and without
     * repeats, and those that are not areas.
     *
     * @param  list<string>|string  $input
     * @return array{areas: list<string>, invalid: list<string>}
     */
    public static function parse(array|string $input): array
    {
        $items = is_string($input) ? preg_split('/[,;\n\r]+/', $input) ?: [] : $input;
        $areas = [];
        $invalid = [];

        foreach ($items as $item) {
            $item = trim((string) $item);

            if ($item === '') {
                continue;
            }

            $area = self::area($item);
            $area === null ? $invalid[] = $item : $areas[$area] = $area;
        }

        return ['areas' => array_values($areas), 'invalid' => $invalid];
    }

    private static function matches(string $area, string $code): bool
    {
        if (! self::uk()) {
            return str_starts_with(str_replace(' ', '', $code), str_replace(' ', '', $area));
        }

        [$outward, $inward] = explode(' ', $code) + [1 => ''];
        [$areaOut, $areaIn] = explode(' ', $area) + [1 => ''];

        return $outward === $areaOut && str_starts_with($inward, $areaIn);
    }

    private static function uk(): bool
    {
        return app(Country::class)->is(Country::DEFAULT);
    }
}
