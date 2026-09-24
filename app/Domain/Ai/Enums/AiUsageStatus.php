<?php

namespace App\Domain\Ai\Enums;

/**
 * Outcome of one model call, stored on ai_usage.
 */
enum AiUsageStatus: string
{
    case Ok = 'ok';
    case Refused = 'refused';
    case Error = 'error';
}
