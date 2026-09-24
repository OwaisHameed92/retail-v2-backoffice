<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiClient;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Data\AiResponse;
use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Ai\Enums\AiUsageStatus;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Support\AiGate;
use Closure;

/**
 * One metered model call: gate (switch, key, company, plan, budget) → provider → ai_usage row.
 * Use this for single-shot features (morning summary, alerts); RunAssistant uses it for each loop step.
 */
final class CallModel
{
    public function __construct(
        private readonly AiClient $client,
        private readonly AiGate $gate,
        private readonly RecordAiUsage $usage,
    ) {}

    /**
     * @param  Closure(string): void|null  $onText  Stream text deltas to this callback.
     *
     * @throws AiUnavailable
     */
    public function handle(AiContext $context, AiRequest $request, ?AiConversation $conversation = null, ?Closure $onText = null): AiResponse
    {
        $this->gate->ensureAvailable($context);

        $started = hrtime(true);

        try {
            $response = $onText === null
                ? $this->client->send($request)
                : $this->client->stream($request, $onText);
        } catch (AiUnavailable $e) {
            $this->usage->handle(
                $context, $request->model, new AiTokenUsage, AiUsageStatus::Error,
                (int) round((hrtime(true) - $started) / 1_000_000), null, $conversation,
            );

            throw $e;
        }

        $this->usage->handle(
            $context,
            $response->model !== '' ? $response->model : $request->model,
            $response->usage,
            $response->isRefusal() ? AiUsageStatus::Refused : AiUsageStatus::Ok,
            $response->latencyMs,
            $response->stopReason,
            $conversation,
        );

        return $response;
    }
}
