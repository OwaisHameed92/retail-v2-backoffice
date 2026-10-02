<?php

namespace App\Domain\Ai\Clients;

use Anthropic\Beta\Messages\BetaMessage;
use Anthropic\Beta\Messages\BetaRawContentBlockDeltaEvent;
use Anthropic\Beta\Messages\BetaTextDelta;
use Anthropic\Client;
use Anthropic\Core\Exceptions\APIConnectionException;
use Anthropic\Core\Exceptions\APIException;
use Anthropic\Core\Exceptions\APIStatusException;
use Anthropic\Core\Exceptions\APITimeoutException;
use Anthropic\Core\Exceptions\AuthenticationException;
use Anthropic\Core\Exceptions\PermissionDeniedException;
use Anthropic\Core\Exceptions\RateLimitException;
use Anthropic\Lib\Streaming\MessageAccumulator;
use Anthropic\Messages\Message;
use App\Domain\Ai\Contracts\AiClient;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Data\AiResponse;
use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Support\AiSettings;
use Closure;
use GuzzleHttp\Client as Guzzle;
use Illuminate\Support\Facades\Log;
use Psr\Http\Client\ClientInterface;

/**
 * Claude through the official Anthropic PHP SDK (`anthropic-ai/sdk`), beta Messages endpoint (needed for
 * server-side refusal fallbacks). Content is passed in wire shape, which the SDK sends unchanged.
 *
 * - Retries: the SDK retries 408/409/429/5xx and connection errors with backoff (`ai.anthropic.max_retries`).
 * - Timeouts: enforced by the Guzzle transport (`ai.anthropic.timeout`).
 * - Per model (`ai.model_options`): adaptive thinking, effort, `fallbacks: "default"`.
 * - Streaming: tools get `eager_input_streaming`; inputs are validated by ToolExecutor before anything runs.
 * - Errors become AiUnavailable with a user-friendly message (busy: 429/503/529, took too long: timeouts, not set up:
 *   401/403, else could not answer); the key and provider messages are never shown, logged or chained.
 */
final class AnthropicAiClient implements AiClient
{
    public const FALLBACK_BETA = 'server-side-fallback-2026-07-01';

    private ?Client $client = null;

    private ?ClientInterface $transporter = null;

    /**
     * Use another PSR-18 transport (tests use a Guzzle MockHandler; no network).
     */
    public function withTransporter(ClientInterface $transporter): self
    {
        $copy = new self;
        $copy->transporter = $transporter;

        return $copy;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey() !== '';
    }

    public function send(AiRequest $request): AiResponse
    {
        $started = hrtime(true);

        try {
            $message = $this->sdk()->beta->messages->create(...$this->params($request, streaming: false));
        } catch (APIException $e) {
            throw $this->unavailable($e, $request);
        }

        return $this->toResponse($message, $started);
    }

    public function stream(AiRequest $request, Closure $onText): AiResponse
    {
        $started = hrtime(true);
        $accumulator = MessageAccumulator::forBetaMessages();

        try {
            $stream = $this->sdk()->beta->messages->createStream(...$this->params($request, streaming: true));

            foreach ($stream as $event) {
                $accumulator->accumulate($event);

                if ($event instanceof BetaRawContentBlockDeltaEvent && $event->delta instanceof BetaTextDelta) {
                    $onText($event->delta->text);
                }
            }
        } catch (APIException $e) {
            throw $this->unavailable($e, $request);
        }

        // A stream cut short (no message_stop) is a failed call. A tool input that was not valid JSON keeps the
        // `{}` placeholder and is then refused by ToolExecutor's validation, so nothing runs on bad input.
        if (! $accumulator->isComplete()) {
            Log::warning('AI stream ended early.', ['model' => $request->model]);

            throw AiUnavailable::providerError();
        }

        $message = $accumulator->message();

        return $this->toResponse($message, $started);
    }

    /**
     * Named arguments for BetaMessagesService::create()/createStream().
     *
     * @return array<string, mixed>
     */
    public function params(AiRequest $request, bool $streaming): array
    {
        $options = AiSettings::modelOptions($request->model);
        $betas = [];

        $params = [
            'model' => $request->model,
            'maxTokens' => $request->maxTokens,
            'system' => $request->system,
            'messages' => $request->messages,
        ];

        if ($request->tools !== []) {
            $params['tools'] = $streaming
                ? array_map(fn (array $tool) => $tool + ['eager_input_streaming' => true], $request->tools)
                : $request->tools;
        }

        if ($request->cacheConversation) {
            $params['cacheControl'] = ['type' => 'ephemeral'];
        }

        if ($options['thinking'] === 'adaptive') {
            $params['thinking'] = ['type' => 'adaptive'];
        }

        if ($options['effort'] && $request->effort !== null) {
            $params['outputConfig'] = ['effort' => $request->effort];
        }

        if ($options['fallbacks']) {
            $params['fallbacks'] = 'default';
            $betas[] = self::FALLBACK_BETA;
        }

        if ($betas !== []) {
            $params['betas'] = $betas;
        }

        return $params;
    }

    private function toResponse(BetaMessage|Message $message, int|float $started): AiResponse
    {
        /** @var array<string, mixed> $wire */
        $wire = (array) json_decode((string) json_encode($message), true);

        /** @var array<string, mixed> $usage */
        $usage = is_array($wire['usage'] ?? null) ? $wire['usage'] : [];
        $stopReason = is_string($wire['stop_reason'] ?? null) ? $wire['stop_reason'] : null;

        $fallbackRan = false;
        foreach ((array) ($usage['iterations'] ?? []) as $iteration) {
            if (is_array($iteration) && ($iteration['type'] ?? null) === 'fallback_message') {
                $fallbackRan = true;
            }
        }

        /** @var list<array<string, mixed>> $content */
        $content = array_values(array_filter((array) ($wire['content'] ?? []), 'is_array'));

        return new AiResponse(
            id: (string) ($wire['id'] ?? ''),
            model: (string) ($wire['model'] ?? ''),
            content: $content,
            stopReason: $stopReason,
            usage: AiTokenUsage::fromWire($usage),
            latencyMs: (int) round((hrtime(true) - $started) / 1_000_000),
            servedByFallback: $fallbackRan && $stopReason !== 'refusal',
        );
    }

    private function unavailable(APIException $e, AiRequest $request): AiUnavailable
    {
        // Log the class and status only: provider messages can echo request content.
        Log::warning('AI provider call failed.', [
            'exception' => $e::class,
            'status' => $e instanceof APIStatusException ? $e->status : null,
            'model' => $request->model,
            'feature' => $request->feature->value,
        ]);

        // The provider exception is not chained: its message can echo request content, and AiUnavailable may be logged.
        return match (true) {
            $e instanceof RateLimitException => AiUnavailable::rateLimited(),
            // 529 overloaded_error and 503: busy, not broken (after the SDK's own retries).
            $e instanceof APIStatusException && in_array($e->status, [503, 529], true) => AiUnavailable::overloaded(),
            $e instanceof AuthenticationException, $e instanceof PermissionDeniedException => AiUnavailable::notConfigured(),
            self::timedOut($e) => AiUnavailable::timedOut(),
            default => AiUnavailable::providerError(), // other 4xx/5xx after retries, connection errors
        };
    }

    /** A transport timeout (Guzzle's cURL error 28 reaches us as a connection error). */
    private static function timedOut(APIException $e): bool
    {
        if ($e instanceof APITimeoutException) {
            return true;
        }

        return $e instanceof APIConnectionException
            && preg_match('/timed out|timeout|cURL error 28/i', (string) $e->getPrevious()?->getMessage()) === 1;
    }

    private function sdk(): Client
    {
        if ($this->client !== null) {
            return $this->client;
        }

        $baseUrl = config('ai.anthropic.base_url');

        return $this->client = new Client(
            apiKey: $this->apiKey(),
            baseUrl: is_string($baseUrl) && $baseUrl !== '' ? $baseUrl : null,
            requestOptions: [
                'transporter' => $this->transporter ?? new Guzzle([
                    'timeout' => (float) config('ai.anthropic.timeout', 120),
                    'connect_timeout' => (float) config('ai.anthropic.connect_timeout', 10),
                ]),
                'maxRetries' => (int) config('ai.anthropic.max_retries', 3),
                'initialRetryDelay' => (float) config('ai.anthropic.initial_retry_delay', 0.5),
                'maxRetryDelay' => (float) config('ai.anthropic.max_retry_delay', 8.0),
            ],
        );
    }

    private function apiKey(): string
    {
        return trim((string) config('ai.anthropic.api_key', ''));
    }
}
