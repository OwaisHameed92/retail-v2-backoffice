<?php

namespace App\Domain\Ai\Exceptions;

use RuntimeException;

/**
 * The actor may not use this conversation, proposal or tool (wrong company, wrong user, missing ability).
 */
final class AiAccessDenied extends RuntimeException
{
    public static function notMember(): self
    {
        return new self('You are not an active member of this business.');
    }

    public static function notYours(): self
    {
        return new self('That item was not found.');
    }

    public static function missingAbility(): self
    {
        return new self('You do not have permission to do that.');
    }
}
