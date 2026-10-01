<?php

namespace App\Domain\Purchasing\Enums;

/**
 * Where an invoice import (module 6.5) is: being read by the model, waiting for the user's review, confirmed, failed
 * to read (the user can retry or enter it by hand) or discarded.
 */
enum InvoiceImportStatus: string
{
    case Reading = 'reading';
    case Review = 'review';
    case Confirmed = 'confirmed';
    case Failed = 'failed';
    case Discarded = 'discarded';

    public function isOpen(): bool
    {
        return in_array($this, [self::Reading, self::Review, self::Failed], true);
    }

    public function label(): string
    {
        return match ($this) {
            self::Reading => 'Reading',
            self::Review => 'To review',
            self::Confirmed => 'Confirmed',
            self::Failed => 'Could not read',
            self::Discarded => 'Discarded',
        };
    }
}
