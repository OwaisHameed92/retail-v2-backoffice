<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Data\AiResponse;
use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Ai\Data\AssistantReply;
use App\Domain\Ai\Data\ToolCallResult;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Ai\Support\AiCost;
use App\Domain\Ai\Support\AiGate;
use App\Domain\Ai\Support\AiRedactor;
use App\Domain\Ai\Support\AiSettings;
use App\Domain\Ai\Support\ConversationStore;
use App\Domain\Ai\Support\CostCast;
use App\Domain\Ai\Support\SystemPrompt;
use App\Domain\Ai\Tools\ToolExecutor;
use App\Domain\Ai\Tools\ToolRegistry;
use App\Domain\Shared\Support\Money;
use Closure;
use Illuminate\Validation\ValidationException;

/**
 * One user turn with the assistant: the tool-use loop.
 *
 * question → model → (tool calls → ToolExecutor → tool results → model)* → answer, at most `ai.max_steps`
 * model calls. Every call is gated and metered by CallModel. Only tools the actor may use are offered, and each
 * call is checked again. Write tools only create proposals (returned in the reply for the UI to confirm).
 */
final class RunAssistant
{
    public const MAX_QUESTION_CHARS = 4000;

    public const REFUSAL_TEXT = "Sorry, I can't help with that request.";

    public const STEP_LIMIT_TEXT = 'I had to stop before finishing because this needed too many steps. Please try a narrower question.';

    public function __construct(
        private readonly CallModel $callModel,
        private readonly AiGate $gate,
        private readonly ConversationStore $store,
        private readonly ToolRegistry $registry,
        private readonly ToolExecutor $executor,
    ) {}

    /**
     * @param  Closure(string): void|null  $onText  Receives streamed text as it arrives.
     *
     * @throws AiUnavailable when no call could be made (not configured, not in plan, budget used, provider down)
     * @throws AiAccessDenied when the conversation belongs to someone else
     * @throws ValidationException for an empty question
     */
    public function handle(AiContext $context, string $question, ?AiConversation $conversation = null, ?Closure $onText = null): AssistantReply
    {
        $question = mb_substr(trim($question), 0, self::MAX_QUESTION_CHARS);

        if ($question === '') {
            throw ValidationException::withMessages(['question' => 'Type a question.']);
        }

        if ($conversation !== null && ! $conversation->isOwnedBy($context)) {
            throw AiAccessDenied::notYours();
        }

        $this->gate->ensureAvailable($context);

        $isNew = $conversation === null;
        $conversation ??= $this->store->start($context, $question);

        /** @var list<AiMessage> $turn */
        $turn = [$this->store->append($conversation, 'user', [['type' => 'text', 'text' => AiRedactor::text($question)]])];

        $tools = $this->registry->definitions($this->registry->availableFor($context));
        $usage = new AiTokenUsage;
        $cost = '0';
        $proposals = [];
        $response = null;
        $stoppedEarly = false;

        for ($step = 1; ; $step++) {
            try {
                $response = $this->callModel->handle($context, $this->request($context, $conversation, $tools), $conversation, $onText);
            } catch (AiUnavailable $e) {
                if ($step === 1) {
                    // Nothing was answered: leave no trace of this turn in the conversation.
                    $isNew ? $conversation->delete() : $this->store->exclude($turn);

                    throw $e;
                }

                return $this->reply($conversation, $e->getMessage(), $proposals, $usage, $cost, $step - 1, null, stoppedEarly: true);
            }

            $usage = $usage->plus($response->usage);
            $cost = Money::add($cost, AiCost::pounds($response->model, $response->usage), CostCast::SCALE);

            if ($response->isRefusal()) {
                // Keep the record, drop the whole turn from future context (a refusal may hold partial output).
                $turn[] = $this->store->append($conversation, 'assistant', $response->content ?: [['type' => 'text', 'text' => '']], $response);
                $this->store->exclude($turn);

                return $this->reply($conversation, self::REFUSAL_TEXT, $proposals, $usage, $cost, $step, $response->stopReason, refused: true);
            }

            $turn[] = $this->store->append($conversation, 'assistant', $this->storableContent($response), $response);

            if (! $response->wantsTools()) {
                break;
            }

            if ($step >= AiSettings::maxSteps()) {
                // Answer every tool call (unrun) so the history stays valid, then stop.
                $results = array_map(
                    fn (array $call) => new ToolCallResult($call['id'], $call['name'], 'Not run: the step limit for this question was reached.', isError: true),
                    $response->toolUses(),
                );
                $turn[] = $this->appendResults($conversation, $results);
                $stoppedEarly = true;

                break;
            }

            $results = [];

            foreach ($response->toolUses() as $call) {
                $result = $this->executor->run($context, $call, $conversation);
                $results[] = $result;

                if ($result->pendingAction !== null) {
                    $proposals[] = $result->pendingAction;
                }
            }

            $turn[] = $this->appendResults($conversation, $results);
        }

        $text = $response->text();

        if ($response->stopReason === 'max_tokens') {
            $text = trim($text."\n\n(My answer was cut short.)");
        }

        if ($stoppedEarly) {
            $text = trim($text."\n\n".self::STEP_LIMIT_TEXT);
        }

        return $this->reply($conversation, $text, $proposals, $usage, $cost, $step, $response->stopReason, $stoppedEarly);
    }

    /**
     * @param  list<array<string, mixed>>  $tools
     */
    private function request(AiContext $context, AiConversation $conversation, array $tools): AiRequest
    {
        return new AiRequest(
            feature: $context->feature,
            model: AiSettings::modelFor($context->feature),
            system: SystemPrompt::blocks($context),
            messages: $this->store->messagesFor($conversation, $context),
            tools: $tools,
            maxTokens: AiSettings::maxTokensFor($context->feature),
            effort: AiSettings::effortFor($context->feature),
        );
    }

    /**
     * The assistant content to keep. A reply cut off by max_tokens may end in an unfinished tool call, which
     * would have no result: drop those. An empty reply gets a placeholder (the API refuses empty content).
     *
     * @return list<array<string, mixed>>
     */
    private function storableContent(AiResponse $response): array
    {
        $content = $response->content;

        if ($response->stopReason !== 'tool_use') {
            $content = array_values(array_filter($content, fn (array $block) => ($block['type'] ?? null) !== 'tool_use'));
        }

        return $content !== [] ? $content : [['type' => 'text', 'text' => '(no reply)']];
    }

    /**
     * @param  list<ToolCallResult>  $results
     */
    private function appendResults(AiConversation $conversation, array $results): AiMessage
    {
        return $this->store->append(
            $conversation,
            'user',
            array_map(fn (ToolCallResult $result) => $result->toBlock(), $results),
            toolResults: array_map(fn (ToolCallResult $result) => [
                'toolUseId' => $result->toolUseId,
                'name' => $result->name,
                'isError' => $result->isError,
                'actionId' => $result->pendingAction?->id,
            ], $results),
        );
    }

    /**
     * @param  list<AiPendingAction>  $proposals
     */
    private function reply(
        AiConversation $conversation,
        string $text,
        array $proposals,
        AiTokenUsage $usage,
        string $cost,
        int $steps,
        ?string $stopReason,
        bool $stoppedEarly = false,
        bool $refused = false,
    ): AssistantReply {
        return new AssistantReply(
            conversation: $conversation->refresh(),
            text: $text,
            proposals: $proposals,
            usage: $usage,
            costGbp: Money::normalise($cost, CostCast::SCALE),
            steps: $steps,
            stopReason: $stopReason,
            stoppedEarly: $stoppedEarly,
            refused: $refused,
        );
    }
}
