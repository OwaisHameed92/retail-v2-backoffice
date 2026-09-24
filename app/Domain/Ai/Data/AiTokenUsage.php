<?php

namespace App\Domain\Ai\Data;

/**
 * Token counts of one model call (from the response `usage`).
 */
final readonly class AiTokenUsage
{
    public function __construct(
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $cacheReadTokens = 0,
        public int $cacheWriteTokens = 0,
    ) {}

    /**
     * @param  array<string, mixed>  $usage  Wire-shape `usage` object.
     */
    public static function fromWire(array $usage): self
    {
        return new self(
            inputTokens: (int) ($usage['input_tokens'] ?? 0),
            outputTokens: (int) ($usage['output_tokens'] ?? 0),
            cacheReadTokens: (int) ($usage['cache_read_input_tokens'] ?? 0),
            cacheWriteTokens: (int) ($usage['cache_creation_input_tokens'] ?? 0),
        );
    }

    /**
     * Tokens counted against the monthly budget.
     */
    public function total(): int
    {
        return $this->inputTokens + $this->outputTokens + $this->cacheReadTokens + $this->cacheWriteTokens;
    }

    public function plus(self $other): self
    {
        return new self(
            $this->inputTokens + $other->inputTokens,
            $this->outputTokens + $other->outputTokens,
            $this->cacheReadTokens + $other->cacheReadTokens,
            $this->cacheWriteTokens + $other->cacheWriteTokens,
        );
    }
}
