<?php

namespace App\Domain\Workshop\Support;

use SensitiveParameter;

/**
 * Our one OAuth client, BikeBook (config workshop.bikebook: id, secret and exact redirect URIs from the owner's
 * registration with BikeBook). Scopes as WORKSHOP-BIKEBOOK.md §1.2.
 */
final class OAuthClient
{
    public const SCOPES = [
        'offline_access', 'products:read', 'inventory:read', 'customers:read', 'customers:write', 'stores:read',
        'taxrates:read', 'salesorders:write', 'webhooks:write',
    ];

    public static function configured(): bool
    {
        return self::id() !== '' && (string) config('workshop.bikebook.client_secret', '') !== '';
    }

    public static function id(): string
    {
        return (string) config('workshop.bikebook.client_id', '');
    }

    public static function isClient(mixed $clientId): bool
    {
        return is_string($clientId) && self::configured() && hash_equals(self::id(), $clientId);
    }

    public static function checkSecret(mixed $clientId, #[SensitiveParameter] mixed $secret): bool
    {
        return self::isClient($clientId) && is_string($secret) && hash_equals((string) config('workshop.bikebook.client_secret'), $secret);
    }

    public static function allowsRedirect(mixed $uri): bool
    {
        return is_string($uri) && in_array($uri, (array) config('workshop.bikebook.redirect_uris', []), true);
    }

    /**
     * The scopes asked for (space separated), all ours when none; null when one is unknown.
     *
     * @return list<string>|null
     */
    public static function scopes(mixed $scope): ?array
    {
        $asked = array_values(array_unique(array_filter(explode(' ', is_string($scope) ? $scope : ''), fn ($s) => $s !== '')));

        if ($asked === []) {
            return self::SCOPES;
        }

        return array_diff($asked, self::SCOPES) === [] ? $asked : null;
    }
}
