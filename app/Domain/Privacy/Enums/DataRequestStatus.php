<?php

namespace App\Domain\Privacy\Enums;

/** Where a data request stands (module 7.7). */
enum DataRequestStatus: string
{
    /** Everything is done (an export downloaded, or an erasure with nothing left on the tills). */
    case Completed = 'completed';

    /** Anonymised on the portal; till-owned records still need the listed steps on the till. */
    case TillPending = 'tillPending';

    public function label(): string
    {
        return match ($this) {
            self::Completed => 'Completed',
            self::TillPending => 'Waiting for the tills',
        };
    }
}
