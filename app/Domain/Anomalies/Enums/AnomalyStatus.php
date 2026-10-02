<?php

namespace App\Domain\Anomalies\Enums;

/**
 * Where a finding stands (module 6.6): new until someone looks, acknowledged ("being looked at") or dismissed with a
 * reason. Every change is audited.
 */
enum AnomalyStatus: string
{
    case New = 'new';
    case Acknowledged = 'acknowledged';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Acknowledged => 'Acknowledged',
            self::Dismissed => 'Dismissed',
        };
    }

    /** Still worth attention (not dismissed). */
    public function open(): bool
    {
        return $this !== self::Dismissed;
    }
}
