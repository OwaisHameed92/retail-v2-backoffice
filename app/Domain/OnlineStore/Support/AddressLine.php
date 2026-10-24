<?php

namespace App\Domain\OnlineStore\Support;

/**
 * One tidy address line from the parts a till or business record holds (OS-11): the parts are split on commas and new
 * lines, trimmed, and a part already given (case-blind, spaces ignored) is left out, so an address that already holds
 * its town and postcode does not repeat them ("1 High St, Birmingham, B23 5GF", never "…, Birmingham, B23 5GF,
 * Birmingham, B23 5GF"). Empty → null.
 *
 *     AddressLine::from($branch->address, $branch->town, $branch->postcode);
 */
final class AddressLine
{
    public static function from(mixed ...$parts): ?string
    {
        $seen = [];
        $out = [];

        foreach ($parts as $part) {
            foreach (preg_split('/[,\r\n]+/', is_scalar($part) ? (string) $part : '') ?: [] as $piece) {
                $piece = trim((string) preg_replace('/\s+/', ' ', $piece));
                $key = strtolower(str_replace(' ', '', $piece));

                if ($piece !== '' && ! isset($seen[$key])) {
                    $seen[$key] = true;
                    $out[] = $piece;
                }
            }
        }

        return $out === [] ? null : implode(', ', $out);
    }
}
