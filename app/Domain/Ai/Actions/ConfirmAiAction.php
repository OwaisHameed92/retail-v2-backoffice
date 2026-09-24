<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiWriteTool;
use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiActionFailed;
use App\Domain\Ai\Exceptions\AiActionNotConfirmable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Ai\Support\ConversationStore;
use App\Domain\Ai\Tools\ToolExecutor;
use App\Domain\Ai\Tools\ToolRegistry;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * A person confirms a change the AI proposed. Runs the write tool's execute() (which calls the real Action)
 * exactly once:
 *
 * - only the proposer (same company and user/admin) can confirm; others get "not found";
 * - the row is claimed under a lock (pending → confirmed) before running, so a double click runs it once;
 * - expired proposals are marked expired and refused; cancelled/failed/confirmed ones are refused;
 * - the actor's ability is re-checked and the stored input validated again, inside the company scope;
 * - audited: `ai.action_confirmed` (with the result) or `ai.action_failed`.
 */
final class ConfirmAiAction
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
        private readonly ConversationStore $store,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws AiAccessDenied|AiActionNotConfirmable|AiActionFailed
     */
    public function handle(string $actionId, AiContext $context): AiPendingAction
    {
        $action = $this->claim($actionId, $context);
        $tool = $this->registry->find($action->tool);

        try {
            if (! $tool instanceof AiWriteTool) {
                throw AiActionFailed::because('This change can no longer be made. Please ask again.');
            }

            $input = $this->executor->validate($tool, $action->input);
            $result = $this->executor->inScope($tool, $context, fn () => $tool->execute($input, $context));
        } catch (Throwable $e) {
            $message = match (true) {
                $e instanceof AiActionFailed => $e->getMessage(),
                $e instanceof ValidationException => implode(' ', $e->validator->errors()->all()),
                $e instanceof ModelNotFoundException => 'The record was not found. It may have been removed.',
                default => 'The change could not be made. Please try again.',
            };

            if (! $e instanceof AiActionFailed && ! $e instanceof ValidationException && ! $e instanceof ModelNotFoundException) {
                report($e);
            }

            $action->forceFill(['status' => PendingActionStatus::Failed, 'error' => mb_substr($message, 0, 1000)])->save();
            $this->audit->handle('ai.action_failed', $action, meta: ['tool' => $action->tool, 'error' => $message],
                actor: $context->actor(), companyId: $action->company_id);
            $this->note($action, "The user confirmed this change but it failed: {$message}");

            throw $e instanceof AiActionFailed ? $e : AiActionFailed::because($message, $e);
        }

        $action->forceFill(['result' => $result, 'executed_at' => now()])->save();
        $this->audit->handle('ai.action_confirmed', $action, after: ['result' => $result], meta: [
            'tool' => $action->tool,
            'preview' => $action->preview,
        ], actor: $context->actor(), companyId: $action->company_id);
        $this->note($action, "The user confirmed this change and it is done: {$action->preview}");

        return $action;
    }

    /**
     * Lock the row, check it may run, and move it to confirmed so no second call can run it.
     */
    private function claim(string $actionId, AiContext $context): AiPendingAction
    {
        // Refusals are thrown after the transaction so an "expired" mark is kept, not rolled back.
        [$action, $refusal] = DB::transaction(function () use ($actionId, $context) {
            $action = AiPendingAction::query()->ownedBy($context)->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null) {
                return [null, AiAccessDenied::notYours()];
            }

            if ($action->status === PendingActionStatus::Pending && $action->hasExpired()) {
                $action->forceFill(['status' => PendingActionStatus::Expired])->save();
            }

            if ($action->status !== PendingActionStatus::Pending) {
                return [$action, new AiActionNotConfirmable($action->status)];
            }

            $tool = $this->registry->find($action->tool);

            if ($tool === null || ! $this->registry->allows($tool, $context)) {
                return [$action, AiAccessDenied::missingAbility()];
            }

            $action->forceFill(['status' => PendingActionStatus::Confirmed, 'confirmed_at' => now()])->save();

            return [$action, null];
        });

        if ($refusal !== null || $action === null) {
            throw $refusal ?? AiAccessDenied::notYours();
        }

        return $action;
    }

    /** Tell the conversation what happened, so the assistant does not think the change is still waiting. */
    private function note(AiPendingAction $action, string $text): void
    {
        $conversation = $action->conversation_id === null ? null : AiConversation::query()->find($action->conversation_id);

        if ($conversation !== null) {
            $this->store->append($conversation, 'user', [[
                'type' => 'text',
                'text' => "<app_event action=\"{$action->id}\">{$text}</app_event>",
            ]]);
        }
    }
}
