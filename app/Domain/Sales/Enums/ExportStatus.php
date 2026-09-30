<?php

namespace App\Domain\Sales\Enums;

/** A queued sales CSV export (module 4.6). */
enum ExportStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Ready = 'ready';
    case Failed = 'failed';

    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
