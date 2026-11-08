<?php

namespace App\Domain\Workshop\Enums;

/** OAuth credentials of a connection (stored as SHA-256 hashes only). */
enum WorkshopTokenKind: string
{
    case Code = 'code';
    case Access = 'access';
    case Refresh = 'refresh';

    public function prefix(): string
    {
        return match ($this) {
            self::Code => 'bbac_',
            self::Access => 'bbat_',
            self::Refresh => 'bbrt_',
        };
    }
}
