<?php

namespace App\Domain\TillHealth\Enums;

/**
 * How recently a till (or a shop) was heard from (module 2.7). camelCase values.
 *
 * - online: synced within `sync_online_minutes` (the syncing main till) or validated within
 *   `validate_online_hours` (any other till);
 * - stale: heard from, but not recently enough to be online;
 * - offline: silent past the offline threshold (see config/till-health.php);
 * - notActivated: the till has no licence yet, or its key was never entered.
 */
enum TillState: string
{
    case Online = 'online';
    case Stale = 'stale';
    case Offline = 'offline';
    case NotActivated = 'notActivated';

    public function label(): string
    {
        return match ($this) {
            self::Online => 'Online',
            self::Stale => 'Stale',
            self::Offline => 'Offline',
            self::NotActivated => 'Not activated',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $state) => ['value' => $state->value, 'label' => $state->label()], self::cases());
    }
}
