<?php

namespace App\Domain\Salon\Availability;

/**
 * Who the client asked for (W3a, salon pack README §4.1 "stylists"): `any` (every online stylist who does the whole
 * basket), `female` / `male` (any of that group, for a "female / male stylist only" wish) or one stylist's userId.
 */
final class StylistChoice
{
    public const ANY = 'any';

    public const GROUPS = ['female', 'male'];

    /**
     * The stylists to look at; empty when nobody fits (an unknown stylist, or one who does not do the basket).
     *
     * @param  list<array{minutes: array<string, int>|int}>  $basket
     * @return list<string>
     */
    public static function candidates(PageMenu $menu, array $basket, string $choice): array
    {
        $able = $menu->stylistsFor($basket);

        if ($choice === self::ANY) {
            return $able;
        }

        if (in_array($choice, self::GROUPS, true)) {
            $stylists = $menu->stylists();

            return array_values(array_filter($able, fn (string $id) => ($stylists[$id]['group'] ?? '') === $choice));
        }

        return in_array($choice, $able, true) ? [$choice] : [];
    }

    /** The request's `requestedUserId`: the stylist when one was named, else null (the till picks). */
    public static function requestedUser(string $choice): ?string
    {
        return $choice === self::ANY || in_array($choice, self::GROUPS, true) ? null : $choice;
    }

    /** A note for the shop when the client asked for a group rather than a person. */
    public static function wish(string $choice): string
    {
        return match ($choice) {
            'female' => 'Would like a female stylist.',
            'male' => 'Would like a male stylist.',
            default => '',
        };
    }
}
