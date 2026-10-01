<?php

use App\Domain\Ai\Actions\RunAssistant;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Data\AiResponse;
use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Domain\Ai\Support\SystemPrompt;
use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Validation\ValidationException;
use Tests\Feature\Ai\AiTestHelpers;

uses(AiTestHelpers::class);

beforeEach(fn () => $this->installFakeAi());

test('the assistant runs a scripted tool call and answers from the result', function () {
    $company = $this->aiCompany(name: 'Khan Mini Mart');
    $context = $this->userContext($company);

    $this->fake->callTool('get_company_overview', [], 'Let me check.')
        ->replyWith('You have 1 branch, Main shop, with 2 tills.');

    $reply = app(RunAssistant::class)->handle($context, 'How many tills do I have?');

    expect($reply->text)->toBe('You have 1 branch, Main shop, with 2 tills.')
        ->and($reply->steps)->toBe(2)
        ->and($reply->stopReason)->toBe('end_turn')
        ->and($reply->proposals)->toBe([])
        ->and($this->fake->requests)->toHaveCount(2);

    $results = $this->fake->toolResultsIn();
    expect($results)->toHaveCount(1)
        ->and($results[0]['is_error'])->toBeFalse()
        ->and($results[0]['content'])->toStartWith('<tool_data tool="get_company_overview">')
        ->and($results[0]['content'])->toContain('"name":"Main shop"')
        ->and($results[0]['content'])->toContain('"tills":2');
});

test('requests carry a frozen cached system prompt, sorted tools and the context block', function () {
    $company = $this->aiCompany(name: 'Khan Mini Mart');
    $context = $this->userContext($company, CompanyRole::Manager);
    $this->fake->replyWith('Hello.');

    app(RunAssistant::class)->handle($context, 'Hi');

    $request = $this->fake->lastRequest();
    expect($request->system)->toHaveCount(1)
        ->and($request->system[0]['text'])->toBe(SystemPrompt::TENANT)
        ->and($request->system[0]['cache_control'])->toBe(['type' => 'ephemeral'])
        ->and($request->cacheConversation)->toBeTrue()
        ->and($request->toolNames())->toBe(collect($request->toolNames())->sort()->values()->all())
        ->and($request->toolNames())->toContain('get_company_overview', 'rename_branch', 'get_sales', 'draft_purchase_order')
        ->and($request->model)->toBe('claude-sonnet-5')
        ->and($request->effort)->toBe('medium');

    $first = $request->messages[0]['content'];
    expect($first[0]['text'])->toContain('<context>')
        ->and($first[0]['text'])->toContain('Business: Khan Mini Mart')
        ->and($first[0]['text'])->toContain('role Manager')
        ->and($first[1]['text'])->toBe('Hi');
});

test('several tool calls in one reply are answered in one user message', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);

    $this->fake->callTools([
        ['name' => 'get_company_overview', 'input' => []],
        ['name' => 'get_company_overview', 'input' => []],
    ])->replyWith('Done.');

    app(RunAssistant::class)->handle($context, 'Overview twice please');

    $last = $this->fake->lastRequest()->messages;
    expect($this->fake->toolResultsIn())->toHaveCount(2)
        ->and($last[count($last) - 2]['role'])->toBe('assistant');
});

test('the conversation is stored and the next question continues it', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);

    $this->fake->callTool('get_company_overview')->replyWith('One branch.')->replyWith('Still one branch.');

    $first = app(RunAssistant::class)->handle($context, 'How many branches?');
    $second = app(RunAssistant::class)->handle($context, 'And now?', $first->conversation);

    $conversation = AiConversation::query()->sole();
    expect($second->conversation->id)->toBe($conversation->id)
        ->and($conversation->title)->toBe('How many branches?')
        ->and($conversation->company_id)->toBe($company->id)
        ->and($conversation->user_id)->toBe($context->userId())
        ->and($conversation->messages()->pluck('role')->all())->toBe(['user', 'assistant', 'user', 'assistant', 'user', 'assistant']);

    $assistant = $conversation->messages()->where('role', 'assistant')->first();
    expect($assistant->tool_calls[0]['name'])->toBe('get_company_overview')
        ->and($assistant->input_tokens)->toBe(100)
        ->and($assistant->model)->toBe('claude-sonnet-5');

    // The follow-up sent the whole history (4 earlier messages + the new question), context only once.
    $messages = $this->fake->lastRequest()->messages;
    expect($messages)->toHaveCount(5)
        ->and(collect($messages)->flatMap(fn ($m) => (array) $m['content'])->filter(fn ($b) => str_contains((string) ($b['text'] ?? ''), '<context>')))->toHaveCount(1)
        ->and(end($messages)['content'][0]['text'])->toBe('And now?');
});

test('empty tool inputs are sent back as JSON objects', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $this->fake->callTool('get_company_overview')->replyWith('Done.');

    app(RunAssistant::class)->handle($context, 'Overview');

    $assistant = $this->fake->lastRequest()->messages[1];
    expect(json_encode($assistant['content'][0]['input']))->toBe('{}');
});

test('the loop stops at the step limit and answers every pending tool call', function () {
    config(['ai.max_steps' => 2]);
    $company = $this->aiCompany();
    $context = $this->userContext($company);

    $this->fake->callTool('get_company_overview')->callTool('get_company_overview');

    $reply = app(RunAssistant::class)->handle($context, 'Loop forever');

    expect($reply->stoppedEarly)->toBeTrue()
        ->and($reply->steps)->toBe(2)
        ->and($reply->text)->toContain(RunAssistant::STEP_LIMIT_TEXT)
        ->and($this->fake->requests)->toHaveCount(2);

    $last = AiMessage::query()->orderByDesc('position')->first();
    expect($last->role)->toBe('user')
        ->and($last->content[0]['type'])->toBe('tool_result')
        ->and($last->content[0]['is_error'])->toBeTrue();
});

test('streaming passes text to the callback', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $this->fake->replyWith('Streamed answer.');
    $chunks = [];

    app(RunAssistant::class)->handle($context, 'Hi', onText: function (string $text) use (&$chunks) {
        $chunks[] = $text;
    });

    expect(implode('', $chunks))->toBe('Streamed answer.');
});

test('a reply cut off by max_tokens drops an unfinished tool call', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $this->fake->push(new AiResponse('msg_1', '', [
        ['type' => 'text', 'text' => 'Part of an answer'],
        ['type' => 'tool_use', 'id' => 'toolu_x', 'name' => 'get_company_overview', 'input' => []],
    ], 'max_tokens', new AiTokenUsage(10, 10)));

    $reply = app(RunAssistant::class)->handle($context, 'Long question');

    expect($reply->text)->toContain('Part of an answer')->toContain('cut short');
    $stored = AiMessage::query()->where('role', 'assistant')->sole();
    expect(collect($stored->content)->pluck('type')->all())->toBe(['text']);
});

test('a refused turn is kept for the record but left out of later context', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $this->fake->refuse()->replyWith('Fine.');

    $reply = app(RunAssistant::class)->handle($context, 'Something refused');
    app(RunAssistant::class)->handle($context, 'Something else', $reply->conversation);

    expect($reply->refused)->toBeTrue()
        ->and($reply->text)->toBe(RunAssistant::REFUSAL_TEXT)
        ->and(AiMessage::query()->where('in_context', false)->count())->toBe(2)
        ->and($this->fake->lastRequest()->messages)->toHaveCount(1)
        ->and($this->fake->lastRequest()->lastUserText())->toContain('Something else');
});

test('a closure can script a reply from the request', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $this->fake->push(fn (AiRequest $request) => new AiResponse('m', '', [['type' => 'text', 'text' => 'Echo: '.$request->lastUserText()]], 'end_turn', new AiTokenUsage));

    $reply = app(RunAssistant::class)->handle($context, 'ping');

    expect($reply->text)->toContain('Echo:')->toContain('ping');
});

test('an empty question is rejected and nothing is sent', function () {
    $context = $this->userContext($this->aiCompany());

    expect(fn () => app(RunAssistant::class)->handle($context, '   '))->toThrow(ValidationException::class)
        ->and($this->fake->requests)->toBe([]);
});

test('a conversation of another user cannot be continued', function () {
    $company = $this->aiCompany();
    $this->fake->replyWith('Hi.');
    $reply = app(RunAssistant::class)->handle($this->userContext($company), 'Hi');

    $other = $this->userContext($company);

    expect(fn () => app(RunAssistant::class)->handle($other, 'Read theirs', $reply->conversation))
        ->toThrow(AiAccessDenied::class);
});
