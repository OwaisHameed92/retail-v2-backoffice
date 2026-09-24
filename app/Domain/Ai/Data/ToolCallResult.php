<?php

namespace App\Domain\Ai\Data;

use App\Domain\Ai\Models\AiPendingAction;

/**
 * The outcome of one tool call, ready to be sent back as a `tool_result` block.
 */
final readonly class ToolCallResult
{
    public function __construct(
        public string $toolUseId,
        public string $name,
        public string $content,
        public bool $isError = false,
        public ?AiPendingAction $pendingAction = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toBlock(): array
    {
        return [
            'type' => 'tool_result',
            'tool_use_id' => $this->toolUseId,
            'content' => $this->content,
            'is_error' => $this->isError,
        ];
    }
}
