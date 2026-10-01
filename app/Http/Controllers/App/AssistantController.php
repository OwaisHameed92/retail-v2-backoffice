<?php

namespace App\Http\Controllers\App;

use App\Domain\Ai\Actions\AskPortalAssistant;
use App\Domain\Ai\Actions\CancelAiAction;
use App\Domain\Ai\Actions\ConfirmAiAction;
use App\Domain\Ai\Actions\DeleteAiConversations;
use App\Domain\Ai\AiContext;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiActionFailed;
use App\Domain\Ai\Exceptions\AiActionNotConfirmable;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Ai\Queries\PortalAssistantStatus;
use App\Domain\Ai\Support\AiGate;
use App\Domain\Ai\Support\Portal\PortalPresenter;
use App\Domain\Tenancy\CurrentCompany;
use App\Http\Controllers\Controller;
use App\Http\Requests\App\AskAssistantRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * The portal assistant panel (module 6.2, `company.can:ai.use`), JSON for the top-bar panel: its status and history,
 * a question (streamed as server-sent events: `text` deltas, then `done` with the answer, or `error`), deleting
 * conversations, and confirming or cancelling a proposed change (6.1 ConfirmAiAction / CancelAiAction).
 */
class AssistantController extends Controller
{
    public function status(Request $request, CurrentCompany $current): JsonResponse
    {
        return response()->json(PortalAssistantStatus::for($this->context($request, $current)));
    }

    public function show(Request $request, string $conversation, CurrentCompany $current): JsonResponse
    {
        $context = $this->context($request, $current);
        $model = AiConversation::query()->ownedBy($context)->findOrFail($conversation);

        return response()->json(['conversation' => PortalPresenter::conversation($model), 'turns' => PortalPresenter::turns($model, $context)]);
    }

    public function ask(AskAssistantRequest $request, CurrentCompany $current, AskPortalAssistant $ask, AiGate $gate): StreamedResponse|JsonResponse
    {
        /** @var User $user */
        $user = $request->user();
        $company = $current->require();
        $context = $this->context($request, $current);
        $conversationId = $request->validated('conversationId');

        // Refusals that need no model call are plain JSON errors, before the stream starts.
        if ($conversationId !== null && ! AiConversation::query()->ownedBy($context)->whereKey($conversationId)->exists()) {
            return response()->json(['message' => 'That conversation was not found.'], 404);
        }

        try {
            $gate->ensureAvailable($context);
        } catch (AiUnavailable $e) {
            return response()->json(['message' => $e->getMessage(), 'reason' => $e->reason->value], 503);
        }

        $question = (string) $request->validated('question');

        return response()->stream(function () use ($ask, $user, $company, $question, $conversationId) {
            $send = function (string $event, array $data): void {
                echo "event: {$event}\ndata: ".json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n\n";

                if (ob_get_level() > 0) {
                    ob_flush();
                }
                flush();
            };

            try {
                $send('done', $ask->handle($user, $company, $question, $conversationId, fn (string $text) => $send('text', ['text' => $text])));
            } catch (AiUnavailable $e) {
                $send('error', ['message' => $e->getMessage(), 'reason' => $e->reason->value]);
            } catch (AiAccessDenied $e) {
                $send('error', ['message' => $e->getMessage()]);
            } catch (ValidationException $e) {
                $send('error', ['message' => collect($e->errors())->flatten()->first() ?? 'Check your question.']);
            } catch (Throwable $e) {
                report($e);
                $send('error', ['message' => 'Something went wrong. Please try again.']);
            }
        }, 200, [
            'Content-Type' => 'text/event-stream; charset=UTF-8',
            'Cache-Control' => 'no-cache, no-store, private',
            'X-Accel-Buffering' => 'no',
        ]);
    }

    public function destroy(Request $request, string $conversation, CurrentCompany $current, DeleteAiConversations $delete): JsonResponse
    {
        try {
            $delete->handle($this->context($request, $current), $conversation);
        } catch (AiAccessDenied) {
            abort(404);
        }

        return response()->json(['deleted' => 1]);
    }

    public function destroyAll(Request $request, CurrentCompany $current, DeleteAiConversations $delete): JsonResponse
    {
        return response()->json(['deleted' => $delete->handle($this->context($request, $current))]);
    }

    public function confirm(Request $request, string $action, CurrentCompany $current, ConfirmAiAction $confirm): JsonResponse
    {
        return $this->decide(fn (AiContext $context) => $confirm->handle($action, $context), $request, $current);
    }

    public function cancel(Request $request, string $action, CurrentCompany $current, CancelAiAction $cancel): JsonResponse
    {
        return $this->decide(fn (AiContext $context) => $cancel->handle($action, $context), $request, $current);
    }

    /**
     * @param  callable(AiContext): AiPendingAction  $decide
     */
    private function decide(callable $decide, Request $request, CurrentCompany $current): JsonResponse
    {
        try {
            return response()->json(['proposal' => PortalPresenter::proposal($decide($this->context($request, $current)))]);
        } catch (AiAccessDenied $e) {
            return response()->json(['message' => $e->getMessage()], $e->getMessage() === AiAccessDenied::notYours()->getMessage() ? 404 : 403);
        } catch (AiActionNotConfirmable|AiActionFailed $e) {
            return response()->json(['message' => $e->getMessage()], $e instanceof AiActionFailed ? 422 : 409);
        }
    }

    private function context(Request $request, CurrentCompany $current): AiContext
    {
        /** @var User $user */
        $user = $request->user();

        try {
            return AiContext::forUser($user, $current->require());
        } catch (AiAccessDenied) {
            abort(403);
        }
    }
}
