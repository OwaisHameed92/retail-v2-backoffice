<?php

namespace App\Domain\Ai\Exceptions;

use RuntimeException;
use Throwable;

/**
 * A confirmed change failed while running the real action. The message is safe to show.
 */
final class AiActionFailed extends RuntimeException
{
    public static function because(string $message, ?Throwable $previous = null): self
    {
        return new self($message, 0, $previous);
    }
}
