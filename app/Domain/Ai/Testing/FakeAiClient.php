<?php

namespace App\Domain\Ai\Testing;

use App\Domain\Ai\Contracts\AiClient;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Data\AiResponse;
use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Ai\Exceptions\AiUnavailable;
use Closure;
use LogicException;

/**
 * Scripted AiClient for tests (no network). Queue replies in order; each send() takes the next one.
 *
 *     $fake = FakeAiClient::install()
 *         ->callTool('get_company_overview', [])
 *         ->replyWith('You have 2 branches.');
 *
 * A scripted step may also be a Closure(AiRequest): AiResponse to reply based on what was sent.
 */
final class FakeAiClient implements AiClient
{
    /** @var list<AiResponse|Closure(AiRequest): AiResponse|AiUnavailable> */
    private array $script = [];

    /** @var list<AiRequest> */
    public array $requests = [];

    private bool $configured = true;

    private int $sequence = 0;

    public function __construct(private AiTokenUsage $usage = new AiTokenUsage(100, 20)) {}

    /** Create a fake and bind it as the AiClient. */
    public static function install(): self
    {
        $fake = new self;
        app()->instance(AiClient::class, $fake);

        return $fake;
    }

    public function notConfigured(): self
    {
        $this->configured = false;

        return $this;
    }

    /** Token usage reported by every following scripted reply. */
    public function usage(int $input, int $output, int $cacheRead = 0, int $cacheWrite = 0): self
    {
        $this->usage = new AiTokenUsage($input, $output, $cacheRead, $cacheWrite);

        return $this;
    }

    public function replyWith(string $text, string $stopReason = 'end_turn'): self
    {
        return $this->push($this->response([['type' => 'text', 'text' => $text]], $stopReason));
    }

    /**
     * @param  array<string, mixed>  $input
     */
    public function callTool(string $name, array $input = [], ?string $text = null): self
    {
        return $this->callTools([['name' => $name, 'input' => $input]], $text);
    }

    /**
     * Several tool calls in one reply (parallel tool use).
     *
     * @param  list<array{name: string, input: array<string, mixed>}>  $calls
     */
    public function callTools(array $calls, ?string $text = null): self
    {
        $content = $text !== null ? [['type' => 'text', 'text' => $text]] : [];

        foreach ($calls as $call) {
            $content[] = [
                'type' => 'tool_use',
                'id' => 'toolu_fake_'.(++$this->sequence),
                'name' => $call['name'],
                'input' => $call['input'],
            ];
        }

        return $this->push($this->response($content, 'tool_use'));
    }

    public function refuse(): self
    {
        return $this->push($this->response([], 'refusal'));
    }

    public function failWith(AiUnavailable $error): self
    {
        $this->script[] = $error;

        return $this;
    }

    /**
     * @param  AiResponse|Closure(AiRequest): AiResponse  $response
     */
    public function push(AiResponse|Closure $response): self
    {
        $this->script[] = $response;

        return $this;
    }

    public function isConfigured(): bool
    {
        return $this->configured;
    }

    public function send(AiRequest $request): AiResponse
    {
        $this->requests[] = $request;

        $next = array_shift($this->script) ?? throw new LogicException('FakeAiClient has no scripted reply left.');

        if ($next instanceof AiUnavailable) {
            throw $next;
        }

        $response = $next instanceof Closure ? $next($request) : $next;

        // Scripted replies report the requested model unless they name one.
        return $response->model !== '' ? $response : new AiResponse(
            $response->id, $request->model, $response->content, $response->stopReason, $response->usage,
            $response->latencyMs, $response->servedByFallback,
        );
    }

    public function stream(AiRequest $request, Closure $onText): AiResponse
    {
        $response = $this->send($request);

        if ($response->text() !== '') {
            $onText($response->text());
        }

        return $response;
    }

    public function lastRequest(): AiRequest
    {
        return $this->requests[array_key_last($this->requests) ?? throw new LogicException('No request was sent.')];
    }

    public function remaining(): int
    {
        return count($this->script);
    }

    /**
     * tool_result blocks of the last user message of a request (default: the last request).
     *
     * @return list<array<string, mixed>>
     */
    public function toolResultsIn(?AiRequest $request = null): array
    {
        $request ??= $this->lastRequest();
        $last = $request->messages[array_key_last($request->messages)] ?? [];
        $results = [];

        foreach ((array) ($last['content'] ?? []) as $block) {
            if (is_array($block) && ($block['type'] ?? null) === 'tool_result') {
                $results[] = $block;
            }
        }

        return $results;
    }

    /**
     * @param  list<array<string, mixed>>  $content
     */
    private function response(array $content, string $stopReason): AiResponse
    {
        return new AiResponse(
            id: 'msg_fake_'.(++$this->sequence),
            model: '',
            content: $content,
            stopReason: $stopReason,
            usage: $this->usage,
            latencyMs: 5,
        );
    }
}
