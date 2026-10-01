<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiPendingAction;
use Illuminate\Support\Facades\DB;

/**
 * Deletes one of the user's own assistant conversations, or all of them (module 6.2: history is per user and
 * deletable). Messages go with the conversation (cascade); proposals still waiting are cancelled and unlinked, so
 * nothing from a deleted conversation can be confirmed later. Usage rows stay (billing) without the link.
 */
final class DeleteAiConversations
{
    /**
     * @return int conversations deleted
     *
     * @throws AiAccessDenied when the conversation is not the user's
     */
    public function handle(AiContext $context, ?string $conversationId = null): int
    {
        $query = AiConversation::query()->ownedBy($context);

        if ($conversationId !== null) {
            $query->whereKey($conversationId);

            if (! (clone $query)->exists()) {
                throw AiAccessDenied::notYours();
            }
        }

        return DB::transaction(function () use ($query) {
            $ids = $query->pluck('id')->all();

            AiPendingAction::query()->whereIn('conversation_id', $ids)->where('status', PendingActionStatus::Pending)
                ->update(['status' => PendingActionStatus::Cancelled, 'cancelled_at' => now()]);
            AiPendingAction::query()->whereIn('conversation_id', $ids)->update(['conversation_id' => null]);
            DB::table('ai_usage')->whereIn('conversation_id', $ids)->update(['conversation_id' => null]);

            return AiConversation::query()->whereKey($ids)->delete();
        });
    }
}
