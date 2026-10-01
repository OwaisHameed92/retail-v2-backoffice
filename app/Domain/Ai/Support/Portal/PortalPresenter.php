<?php

namespace App\Domain\Ai\Support\Portal;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Shared\Support\ApiDate;
use Illuminate\Support\Collection;

/**
 * Shapes the portal assistant's data for the panel (module 6.2): conversations, the turns of one (the person's
 * questions and the assistant's final answers, never tool data), and proposals with their state.
 */
final class PortalPresenter
{
    /**
     * @return array<string, mixed>
     */
    public static function conversation(AiConversation $conversation): array
    {
        return [
            'id' => $conversation->id,
            'title' => $conversation->title ?: 'Conversation',
            'lastMessageAt' => ApiDate::format($conversation->last_message_at ?? $conversation->created_at),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function proposal(AiPendingAction $action): array
    {
        $status = $action->status->value === 'pending' && $action->hasExpired() ? 'expired' : $action->status->value;
        $result = $action->result ?? [];

        return [
            'id' => $action->id,
            'preview' => $action->preview,
            'status' => $status,
            'expiresAt' => ApiDate::format($action->expires_at),
            'error' => $action->error,
            'href' => is_string($result['href'] ?? null) && str_starts_with($result['href'], '/app/') ? $result['href'] : null,
        ];
    }

    /**
     * The visible turns of a conversation: each question, then the assistant's answer (its last text in that turn),
     * the links stored with it and the proposals made in it.
     *
     * @return list<array<string, mixed>>
     */
    public static function turns(AiConversation $conversation, AiContext $context): array
    {
        /** @var Collection<int, AiMessage> $messages */
        $messages = AiMessage::query()->where('conversation_id', $conversation->id)->orderBy('position')->get();
        $proposals = AiPendingAction::query()->ownedBy($context)->where('conversation_id', $conversation->id)->orderBy('created_at')->get();
        $turns = [];
        $current = null;

        foreach ($messages as $message) {
            if ($message->role === 'user' && ! $message->isToolResult()) {
                $text = self::text($message);

                if (str_starts_with($text, '<app_event')) {
                    continue;
                }

                if ($current !== null) {
                    $turns[] = $current;
                }

                $current = ['id' => $message->id, 'question' => $text, 'answer' => '', 'links' => [], 'proposalIds' => [], 'refused' => false, 'at' => ApiDate::format($message->created_at)];

                continue;
            }

            if ($current === null) {
                continue;
            }

            if ($message->role === 'assistant' && $message->stop_reason === 'refusal') {
                $current['refused'] = true;
            } elseif ($message->role === 'assistant') {
                $text = self::text($message);
                $current['answer'] = $text !== '' && $text !== '(no reply)' ? $text : $current['answer'];
                $current['links'] = $message->links ?? $current['links'];
            }

            foreach ($message->tool_results ?? [] as $result) {
                if (is_string($result['actionId'] ?? null)) {
                    $current['proposalIds'][] = $result['actionId'];
                }
            }
        }

        if ($current !== null) {
            $turns[] = $current;
        }

        $byId = $proposals->keyBy('id');

        return array_map(function (array $turn) use ($byId) {
            $turn['proposals'] = array_values(array_filter(array_map(
                fn (string $id) => $byId->has($id) ? self::proposal($byId->get($id)) : null,
                $turn['proposalIds'],
            )));
            unset($turn['proposalIds']);

            return $turn;
        }, $turns);
    }

    private static function text(AiMessage $message): string
    {
        $parts = [];

        foreach ($message->content as $block) {
            if (($block['type'] ?? null) === 'text' && is_string($block['text'] ?? null) && ! str_starts_with($block['text'], '<context>')) {
                $parts[] = $block['text'];
            }
        }

        return trim(implode("\n\n", $parts));
    }
}
