<?php

use App\Domain\Ai\Clients\AnthropicAiClient;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Enums\AiUnavailableReason;
use App\Domain\Ai\Exceptions\AiUnavailable;
use GuzzleHttp\Client as Guzzle;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;

/*
 * The Anthropic client against a Guzzle MockHandler: real SDK, no network.
 */

beforeEach(function () {
    config([
        'ai.anthropic.api_key' => 'sk-ant-test-key',
        'ai.anthropic.max_retries' => 2,
        'ai.anthropic.initial_retry_delay' => 0.001,
        'ai.anthropic.max_retry_delay' => 0.002,
    ]);
    $this->history = [];
});

/**
 * @param  list<Response>  $responses
 */
function anthropicWith(object $test, array $responses): AnthropicAiClient
{
    $stack = HandlerStack::create(new MockHandler($responses));
    $stack->push(Middleware::history($test->history));

    return (new AnthropicAiClient)->withTransporter(new Guzzle(['handler' => $stack]));
}

function messageJson(array $content, string $stopReason = 'end_turn', string $model = 'claude-opus-5', array $usage = []): Response
{
    return new Response(200, ['content-type' => 'application/json'], (string) json_encode([
        'id' => 'msg_01', 'type' => 'message', 'role' => 'assistant', 'model' => $model, 'content' => $content,
        'stop_reason' => $stopReason, 'stop_sequence' => null,
        'usage' => $usage + ['input_tokens' => 120, 'output_tokens' => 30, 'cache_read_input_tokens' => 900, 'cache_creation_input_tokens' => 0],
    ]));
}

function sampleRequest(string $model = 'claude-opus-5', ?string $effort = 'medium'): AiRequest
{
    return new AiRequest(
        feature: AiFeature::Assistant,
        model: $model,
        system: [['type' => 'text', 'text' => 'Rules', 'cache_control' => ['type' => 'ephemeral']]],
        messages: [
            ['role' => 'user', 'content' => [['type' => 'text', 'text' => 'Hi']]],
            ['role' => 'assistant', 'content' => [['type' => 'tool_use', 'id' => 'toolu_1', 'name' => 'get_company_overview', 'input' => (object) []]]],
            ['role' => 'user', 'content' => [['type' => 'tool_result', 'tool_use_id' => 'toolu_1', 'content' => '<tool_data>{}</tool_data>', 'is_error' => false]]],
        ],
        tools: [['name' => 'get_company_overview', 'description' => 'Overview', 'input_schema' => ['type' => 'object', 'properties' => (object) []]]],
        maxTokens: 16000,
        effort: $effort,
    );
}

test('an Opus request carries adaptive thinking, effort, caching and the refusal fallback', function () {
    $client = anthropicWith($this, [messageJson([['type' => 'text', 'text' => 'Hello']])]);

    $response = $client->send(sampleRequest());

    $request = $this->history[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect((string) $request->getUri())->toBe('https://api.anthropic.com/v1/messages?beta=true')
        ->and($request->getHeaderLine('x-api-key'))->toBe('sk-ant-test-key')
        ->and($request->getHeaderLine('anthropic-beta'))->toBe(AnthropicAiClient::FALLBACK_BETA)
        ->and($body['model'])->toBe('claude-opus-5')
        ->and($body['max_tokens'])->toBe(16000)
        ->and($body['fallbacks'])->toBe('default')
        ->and($body['thinking'])->toBe(['type' => 'adaptive'])
        ->and($body['output_config'])->toBe(['effort' => 'medium'])
        ->and($body['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['system'][0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($body['tools'][0]['name'])->toBe('get_company_overview')
        ->and($body['tools'][0])->not->toHaveKey('eager_input_streaming')
        ->and($body['messages'][2]['content'][0]['tool_use_id'])->toBe('toolu_1')
        ->and(str_contains((string) $request->getBody(), '"input":{}'))->toBeTrue();

    expect($response->text())->toBe('Hello')
        ->and($response->stopReason)->toBe('end_turn')
        ->and($response->usage->inputTokens)->toBe(120)
        ->and($response->usage->cacheReadTokens)->toBe(900)
        ->and($response->servedByFallback)->toBeFalse();
});

test('a Haiku request sends no thinking, effort or fallbacks', function () {
    $client = anthropicWith($this, [messageJson([['type' => 'text', 'text' => 'Hi']], model: 'claude-haiku-4-5')]);

    $client->send(sampleRequest('claude-haiku-4-5', 'medium'));

    $request = $this->history[0]['request'];
    $body = json_decode((string) $request->getBody(), true);

    expect($body)->not->toHaveKeys(['thinking', 'output_config', 'fallbacks'])
        ->and($request->getHeaderLine('anthropic-beta'))->toBe('');
});

test('rate limits and server errors are retried by the SDK', function () {
    $client = anthropicWith($this, [
        new Response(429, ['retry-after' => '0'], '{"type":"error","error":{"type":"rate_limit_error","message":"slow down"}}'),
        new Response(529, [], '{"type":"error","error":{"type":"overloaded_error","message":"busy"}}'),
        messageJson([['type' => 'text', 'text' => 'Third time lucky']]),
    ]);

    expect($client->send(sampleRequest())->text())->toBe('Third time lucky')
        ->and($this->history)->toHaveCount(3);
});

test('errors after the retries become safe AiUnavailable errors', function (int $status, string $type, AiUnavailableReason $reason) {
    $body = (string) json_encode(['type' => 'error', 'error' => ['type' => $type, 'message' => 'secret provider detail']]);
    $client = anthropicWith($this, array_map(fn () => new Response($status, [], $body), range(1, 3)));

    try {
        $client->send(sampleRequest());
        $this->fail('Expected AiUnavailable.');
    } catch (AiUnavailable $e) {
        expect($e->reason)->toBe($reason)->and($e->getMessage())->not->toContain('secret provider detail');
    }
})->with([
    'rate limited' => [429, 'rate_limit_error', AiUnavailableReason::RateLimited],
    'server error' => [500, 'api_error', AiUnavailableReason::ProviderError],
    'bad key' => [401, 'authentication_error', AiUnavailableReason::NotConfigured],
]);

test('a fallback-served reply is flagged', function () {
    $client = anthropicWith($this, [messageJson([['type' => 'text', 'text' => 'From the fallback']], model: 'claude-opus-4-8', usage: [
        'iterations' => [['type' => 'fallback_message', 'input_tokens' => 1, 'output_tokens' => 1]],
    ])]);

    $response = $client->send(sampleRequest());

    expect($response->servedByFallback)->toBeTrue()->and($response->model)->toBe('claude-opus-4-8');
});

test('streaming delivers text deltas and assembles tool calls', function () {
    $event = fn (string $type, array $data) => "event: {$type}\ndata: ".json_encode($data)."\n\n";
    $sse = $event('message_start', ['type' => 'message_start', 'message' => ['id' => 'msg_s', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 50, 'output_tokens' => 1]]])
        .$event('content_block_start', ['type' => 'content_block_start', 'index' => 0, 'content_block' => ['type' => 'text', 'text' => '']])
        .$event('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'Let me ']])
        .$event('content_block_delta', ['type' => 'content_block_delta', 'index' => 0, 'delta' => ['type' => 'text_delta', 'text' => 'check.']])
        .$event('content_block_stop', ['type' => 'content_block_stop', 'index' => 0])
        .$event('content_block_start', ['type' => 'content_block_start', 'index' => 1, 'content_block' => ['type' => 'tool_use', 'id' => 'toolu_9', 'name' => 'rename_branch', 'input' => (object) []]])
        .$event('content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '{"new_name":']])
        .$event('content_block_delta', ['type' => 'content_block_delta', 'index' => 1, 'delta' => ['type' => 'input_json_delta', 'partial_json' => '"High Street"}']])
        .$event('content_block_stop', ['type' => 'content_block_stop', 'index' => 1])
        .$event('message_delta', ['type' => 'message_delta', 'delta' => ['stop_reason' => 'tool_use', 'stop_sequence' => null], 'usage' => ['output_tokens' => 22]])
        .$event('message_stop', ['type' => 'message_stop']);

    $client = anthropicWith($this, [new Response(200, ['content-type' => 'text/event-stream'], $sse)]);
    $chunks = [];

    $response = $client->stream(sampleRequest(), function (string $text) use (&$chunks) {
        $chunks[] = $text;
    });

    $body = json_decode((string) $this->history[0]['request']->getBody(), true);

    expect($chunks)->toBe(['Let me ', 'check.'])
        ->and($body['stream'])->toBeTrue()
        ->and($body['tools'][0]['eager_input_streaming'])->toBeTrue()
        ->and($response->text())->toBe('Let me check.')
        ->and($response->toolUses())->toBe([['id' => 'toolu_9', 'name' => 'rename_branch', 'input' => ['new_name' => 'High Street']]])
        ->and($response->usage->outputTokens)->toBe(22);
});

test('a stream that ends early is a provider error', function () {
    $sse = "event: message_start\ndata: ".json_encode(['type' => 'message_start', 'message' => ['id' => 'm', 'type' => 'message', 'role' => 'assistant', 'model' => 'claude-opus-5', 'content' => [], 'stop_reason' => null, 'stop_sequence' => null, 'usage' => ['input_tokens' => 1, 'output_tokens' => 1]]])."\n\n";
    $client = anthropicWith($this, [new Response(200, ['content-type' => 'text/event-stream'], $sse)]);

    expect(fn () => $client->stream(sampleRequest(), fn () => null))->toThrow(AiUnavailable::class);
});

test('the client is not configured without a key', function () {
    config(['ai.anthropic.api_key' => '  ']);

    expect((new AnthropicAiClient)->isConfigured())->toBeFalse();
});
