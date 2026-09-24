<?php

namespace App\Domain\Ai\Support;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Data\AiResponse;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use Illuminate\Support\Str;

/**
 * Persists conversations and rebuilds the `messages` array for the next request.
 *
 * History is append-only (what was sent stays byte-identical, so earlier turns stay cached). Messages of a
 * refused turn are kept for the record but marked `in_context = false`. Long conversations send only the last
 * `ai.history_messages`, starting at a real question so no tool result is orphaned.
 */
final class ConversationStore
{
    public function start(AiContext $context, string $firstQuestion): AiConversation
    {
        return AiConversation::query()->create([
            'company_id' => $context->companyId(),
            'user_id' => $context->userId(),
            'admin_id' => $context->adminId(),
            'feature' => $context->feature,
            'title' => Str::limit(AiRedactor::text(trim($firstQuestion)), 117),
            'last_message_at' => now(),
        ]);
    }

    /**
     * @param  list<array<string, mixed>>  $content  Wire-shape blocks.
     * @param  list<array<string, mixed>>|null  $toolResults  Summary of tool results (name, error, action id).
     */
    public function append(
        AiConversation $conversation,
        string $role,
        array $content,
        ?AiResponse $response = null,
        ?array $toolResults = null,
    ): AiMessage {
        $position = (int) AiMessage::query()->where('conversation_id', $conversation->id)->max('position') + 1;

        $toolCalls = $response?->toolUses();

        $message = AiMessage::query()->create([
            'conversation_id' => $conversation->id,
            'position' => $position,
            'role' => $role,
            'content' => $content,
            'tool_calls' => $toolCalls === null || $toolCalls === [] ? null : array_map(
                fn (array $call) => ['id' => $call['id'], 'name' => $call['name']],
                $toolCalls,
            ),
            'tool_results' => $toolResults,
            'model' => $response?->model,
            'stop_reason' => $response?->stopReason,
            'input_tokens' => $response->usage->inputTokens ?? 0,
            'output_tokens' => $response->usage->outputTokens ?? 0,
            'cache_read_tokens' => $response->usage->cacheReadTokens ?? 0,
            'cache_write_tokens' => $response->usage->cacheWriteTokens ?? 0,
        ]);

        $conversation->forceFill(['last_message_at' => now()])->save();

        return $message;
    }

    /**
     * @param  list<AiMessage>  $messages
     */
    public function exclude(array $messages): void
    {
        $ids = array_map(fn (AiMessage $message) => $message->id, $messages);

        if ($ids !== []) {
            AiMessage::query()->whereIn('id', $ids)->update(['in_context' => false]);
        }
    }

    /**
     * The `messages` array for the next request, with the context block in front of the first question.
     *
     * @return list<array<string, mixed>>
     */
    public function messagesFor(AiConversation $conversation, AiContext $context): array
    {
        $messages = AiMessage::query()
            ->where('conversation_id', $conversation->id)
            ->where('in_context', true)
            ->orderByDesc('position')
            ->limit(AiSettings::historyMessages())
            ->get()
            ->reverse()
            ->values();

        // Start at a question from the person, never at an assistant turn or an orphaned tool result.
        while ($messages->isNotEmpty() && ($messages->first()->role !== 'user' || $messages->first()->isToolResult())) {
            $messages->shift();
        }

        $wire = $messages->map(fn (AiMessage $message) => [
            'role' => $message->role,
            'content' => array_map(self::forApi(...), $message->content),
        ])->values()->all();

        if ($wire !== []) {
            array_unshift($wire[0]['content'], ['type' => 'text', 'text' => SystemPrompt::context($context)]);
        }

        return $wire;
    }

    /**
     * JSON round trips turn an empty tool input `{}` into `[]`; the API needs an object.
     *
     * @param  array<string, mixed>  $block
     * @return array<string, mixed>
     */
    private static function forApi(array $block): array
    {
        if (in_array($block['type'] ?? null, ['tool_use', 'server_tool_use'], true) && ($block['input'] ?? []) === []) {
            $block['input'] = (object) [];
        }

        return $block;
    }
}
