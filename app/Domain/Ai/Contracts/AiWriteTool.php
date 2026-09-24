<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\AiContext;

/**
 * A tool that changes data. `handle()` only proposes; `execute()` is called by ConfirmAiAction after a person
 * confirms, and must call the real domain Action (which does its own validation, locking and audit).
 */
interface AiWriteTool extends AiTool
{
    /**
     * @param  array<string, mixed>  $input  The proposal's input, validated again.
     * @return array<string, mixed> A short result for the conversation.
     */
    public function execute(array $input, AiContext $context): array;
}
