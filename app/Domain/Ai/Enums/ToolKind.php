<?php

namespace App\Domain\Ai\Enums;

/**
 * Read tools run immediately and return data. Write tools never change anything themselves: they return a
 * proposal that a person confirms (ConfirmAiAction).
 */
enum ToolKind: string
{
    case Read = 'read';
    case Write = 'write';
}
