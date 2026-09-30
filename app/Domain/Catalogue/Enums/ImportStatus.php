<?php

namespace App\Domain\Catalogue\Enums;

/** Where a product CSV import is: uploaded (mapping and preview), queued, running, completed or failed. */
enum ImportStatus: string
{
    case Uploaded = 'uploaded';
    case Queued = 'queued';
    case Running = 'running';
    case Completed = 'completed';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Uploaded => 'Waiting for you',
            self::Queued => 'Queued',
            self::Running => 'Importing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    public function isApplying(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
