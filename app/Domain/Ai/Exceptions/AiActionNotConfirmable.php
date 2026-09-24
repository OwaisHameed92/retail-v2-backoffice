<?php

namespace App\Domain\Ai\Exceptions;

use App\Domain\Ai\Enums\PendingActionStatus;
use RuntimeException;

/**
 * A proposed change can no longer be confirmed or cancelled (already done, cancelled, expired or failed).
 */
final class AiActionNotConfirmable extends RuntimeException
{
    public function __construct(public readonly PendingActionStatus $status)
    {
        parent::__construct(match ($status) {
            PendingActionStatus::Confirmed => 'This change has already been confirmed.',
            PendingActionStatus::Cancelled => 'This change was cancelled.',
            PendingActionStatus::Expired => 'This change has expired. Please ask again.',
            PendingActionStatus::Failed => 'This change could not be made. Please ask again.',
            PendingActionStatus::Pending => 'This change is waiting for confirmation.',
        });
    }
}
