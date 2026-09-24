<?php

namespace App\Domain\Ai\Data;

/**
 * One Messages API response in wire shape. `content` is appended to the conversation unchanged (thinking blocks
 * keep their signatures; fallback blocks stay in place).
 */
final readonly class AiResponse
{
    /**
     * @param  list<array<string, mixed>>  $content
     */
    public function __construct(
        public string $id,
        public string $model,
        public array $content,
        public ?string $stopReason,
        public AiTokenUsage $usage,
        public int $latencyMs = 0,
        public bool $servedByFallback = false,
    ) {}

    public function text(): string
    {
        $texts = [];

        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'text') {
                $texts[] = (string) ($block['text'] ?? '');
            }
        }

        return trim(implode("\n\n", $texts));
    }

    /**
     * @return list<array{id: string, name: string, input: array<string, mixed>}>
     */
    public function toolUses(): array
    {
        $uses = [];

        foreach ($this->content as $block) {
            if (($block['type'] ?? null) === 'tool_use') {
                $input = $block['input'] ?? [];
                $uses[] = [
                    'id' => (string) ($block['id'] ?? ''),
                    'name' => (string) ($block['name'] ?? ''),
                    'input' => is_array($input) ? $input : (array) $input,
                ];
            }
        }

        return $uses;
    }

    public function wantsTools(): bool
    {
        return $this->stopReason === 'tool_use' && $this->toolUses() !== [];
    }

    public function isRefusal(): bool
    {
        return $this->stopReason === 'refusal';
    }
}
