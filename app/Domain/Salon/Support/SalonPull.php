<?php

namespace App\Domain\Salon\Support;

use App\Domain\TillData\Registry\EntityDefinition;
use Illuminate\Database\Query\Builder;

/**
 * What a pull sends of the salon part (salon pack 2026-11-08, README Part C "refusals"; W2). Used by PullFeed,
 * PullVisibility and PullEnvelopes.
 *
 * To a shop that is not a salon (SalonShops::isSalonBranch): no Salon* row at all (the tables are left out of the
 * feed), no `salon.*` or `vertical.salon` setting, no `salon.*` right, and no `salon.*` entry inside a Role's
 * permissions; its till would refuse them and log it. For a business with no salon this changes nothing: those rows
 * do not exist, so its pull is exactly as before.
 *
 * To a salon: the hub-owned salon rows like Product (company-wide ones to every salon shop, a shop's own SalonResource
 * to that shop), and a SalonOnlineBooking request only to the shop it names (never one with no shop).
 */
final class SalonPull
{
    /** The table is left out of this shop's feed. */
    public static function skips(EntityDefinition $def, bool $salon): bool
    {
        return ! $salon && SalonShops::isSalonEntity($def->entity);
    }

    /** No `salon.*` / `vertical.salon` setting for a shop that is not a salon. */
    public static function withoutSalonSettings(Builder $query): Builder
    {
        return $query->where('setting_key', 'not like', 'salon.%')->where('setting_key', '<>', SalonShops::FLAG);
    }

    /** No `salon.*` right for a shop that is not a salon. */
    public static function withoutSalonRights(Builder $query): Builder
    {
        return $query->where('permission_key', 'not like', 'salon.%');
    }

    /** A request goes to the shop it names, and only there. */
    public static function bookingsFor(Builder $query, string $branchId): Builder
    {
        return $query->where('branch_id', $branchId);
    }

    /**
     * A Role envelope for a shop that is not a salon: its `salon.*` permissions left out (the role's other rights as
     * stored). Any other envelope is returned as it is.
     *
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    public static function envelope(array $envelope, bool $salon): array
    {
        if ($salon || $envelope['entity'] !== 'Role' || ! is_array($envelope['payload']['permissions'] ?? null)) {
            return $envelope;
        }

        $envelope['payload']['permissions'] = array_values(array_filter(
            $envelope['payload']['permissions'],
            // The schema's {roleId, permissionKey, role} items (decoded as objects by PullPayload), or a bare key.
            fn (mixed $p) => ! is_string($key = match (true) {
                is_object($p) => $p->permissionKey ?? null,
                is_array($p) => $p['permissionKey'] ?? null,
                default => $p,
            }) || ! SalonShops::isSalonRight($key),
        ));

        return $envelope;
    }
}
