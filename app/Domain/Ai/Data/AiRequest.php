<?php

namespace App\Domain\Ai\Data;

use App\Domain\Ai\Enums\AiFeature;

/**
 * One Messages API request, provider-neutral enough for FakeAiClient. Content uses the API wire shape
 * (snake_case keys: `tool_use_id`, `input_schema`, `cache_control`), exactly what is stored in ai_messages.
 *
 * Caching: `system` blocks are frozen text (the last carries cache_control, which also caches `tools`, as tools
 * render first); `cacheConversation` adds top-level automatic caching of the latest message.
 */
final readonly class AiRequest
{
    /**
     * @param  list<array<string, mixed>>  $system
     * @param  list<array<string, mixed>>  $tools
     * @param  list<array<string, mixed>>  $messages
     */
    public function __construct(
        public AiFeature $feature,
        public string $model,
        public array $system,
        public array $messages,
        public array $tools = [],
        public int $maxTokens = 16000,
        public ?string $effort = null,
        public bool $cacheConversation = true,
    ) {}

    /**
     * The text of the last user message (handy in tests).
     */
    public function lastUserText(): string
    {
        for ($i = count($this->messages) - 1; $i >= 0; $i--) {
            $message = $this->messages[$i];

            if (($message['role'] ?? null) !== 'user') {
                continue;
            }

            $content = $message['content'] ?? '';

            if (is_string($content)) {
                return $content;
            }

            $texts = [];

            foreach (is_array($content) ? $content : [] as $block) {
                if (is_array($block) && ($block['type'] ?? null) === 'text') {
                    $texts[] = (string) ($block['text'] ?? '');
                }
            }

            return implode("\n", $texts);
        }

        return '';
    }

    /**
     * @return list<string>
     */
    public function toolNames(): array
    {
        return array_map(fn (array $tool) => (string) ($tool['name'] ?? ''), $this->tools);
    }
}
