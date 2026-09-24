<?php

namespace App\Domain\Ai\Contracts;

use App\Domain\Ai\Clients\AnthropicAiClient;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Data\AiResponse;
use App\Domain\Ai\Exceptions\AiUnavailable;
use Closure;
use Illuminate\Container\Attributes\Bind;

/**
 * The model provider. Bound to Anthropic; tests swap in App\Domain\Ai\Testing\FakeAiClient.
 *
 * Callers normally go through CallModel (budget checks, metering) or RunAssistant (tool loop), not this directly.
 */
#[Bind(AnthropicAiClient::class)]
interface AiClient
{
    /** Whether a provider key is set. Without one, AI features report "not set up yet". */
    public function isConfigured(): bool;

    /**
     * @throws AiUnavailable on provider errors (after the SDK's retries)
     */
    public function send(AiRequest $request): AiResponse;

    /**
     * Streams the reply, calling $onText with each text delta, and returns the complete message.
     *
     * @param  Closure(string): void  $onText
     *
     * @throws AiUnavailable
     */
    public function stream(AiRequest $request, Closure $onText): AiResponse;
}
