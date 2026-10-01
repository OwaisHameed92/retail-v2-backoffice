<?php

namespace App\Domain\MasterCatalogue\Enums;

/** Review state of a barcode collected from tills. */
enum ContributionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'To review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
        };
    }
}
