<?php

namespace App\Domain\TillData\Sync;

/**
 * Ids of keyed rows (contract v1.4 §10.3, the till's `SyncRowIds.cs`): Setting and RolePermission have no ULID of
 * their own. Their id is the first 130 bits of SHA-256 of `Setting|{scope}|{scopeId}|{key}` or
 * `RolePermission|{roleId}|{permissionKey}`, in Crockford base32 (26 characters). The portal derives it from the
 * payload with its own company/branch ids, so one setting is one row whichever till id it came under.
 */
final class SyncRowIds
{
    public const PATTERN = '/^[0-9A-HJKMNP-TV-Z]{26}$/';

    private const ALPHABET = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    public static function setting(string $scope, string $scopeId, string $key): string
    {
        return self::of("Setting|{$scope}|{$scopeId}|{$key}");
    }

    public static function rolePermission(string $roleId, string $permissionKey): string
    {
        return self::of("RolePermission|{$roleId}|{$permissionKey}");
    }

    public static function of(string $text): string
    {
        $bits = '';

        foreach (str_split(substr(hash('sha256', $text, true), 0, 17)) as $byte) {
            $bits .= str_pad(decbin(ord($byte)), 8, '0', STR_PAD_LEFT);
        }

        $id = '';

        foreach (str_split(substr($bits, 0, 130), 5) as $group) {
            $id .= self::ALPHABET[bindec($group)];
        }

        return $id;
    }
}
