<?php

namespace App\Domain\Sync\Enums;

/** A shop's history upload (contract v1.4.1 §17.8): open until `migrate/complete` finds every row, then complete. */
enum CloudUploadStatus: string
{
    case Open = 'open';
    case Complete = 'complete';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Uploading',
            self::Complete => 'Complete',
        };
    }
}
