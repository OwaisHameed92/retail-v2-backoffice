<?php

namespace App\Domain\Workshop\Enums;

/**
 * A BikeBook connection's state (WORKSHOP-BIKEBOOK.md §1.1), also the value of the branch setting
 * `workshop.connector_status` the portal writes for the shop's till (§6).
 *
 * - `pending`: Connect was pressed; waiting for the shop owner's OAuth consent or an API key.
 * - `connected`: BikeBook may call; orders go to the till.
 * - `failed`: still connected, but a webhook to BikeBook was given up (`lastError`); calls and orders carry on and the
 *   next delivered webhook makes it `connected` again.
 * - `disconnected`: tokens and key revoked, BikeBook's calls get 401, nothing goes to the till.
 */
enum ConnectionStatus: string
{
    case Pending = 'pending';
    case Connected = 'connected';
    case Failed = 'failed';
    case Disconnected = 'disconnected';

    /** BikeBook may call and orders go to the shop's till. */
    public function carriesTraffic(): bool
    {
        return $this === self::Connected || $this === self::Failed;
    }

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting to connect',
            self::Connected => 'Connected',
            self::Failed => 'Connected, webhook failing',
            self::Disconnected => 'Disconnected',
        };
    }
}
