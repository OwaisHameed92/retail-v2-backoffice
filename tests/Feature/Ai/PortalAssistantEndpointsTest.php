<?php

use App\Domain\Ai\Actions\RunAssistant;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Ai\Testing\FakeAiClient;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\Enums\CompanyRole;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\Ai\PortalAssistantHelpers;

/*
 * Module 6.2: the assistant panel's endpoints: who may use them, the "not set up yet" state, the monthly allowance
 * and its metering on /app/billing, per-user history (deletable), refusals and personal details kept out of prompts.
 */

uses(PortalAssistantHelpers::class);

beforeEach(fn () => $this->setUpPortal());

test('guests are sent to log in and till staff get 403 on every assistant route', function () {
    $id = '01K5T0Q8C4000000000000AAAA';
    $routes = [['get', '/app/assistant'], ['post', '/app/assistant/ask'], ['get', "/app/assistant/conversations/{$id}"],
        ['delete', "/app/assistant/conversations/{$id}"], ['delete', '/app/assistant/conversations'],
        ['post', "/app/assistant/actions/{$id}/confirm"], ['post', "/app/assistant/actions/{$id}/cancel"]];

    foreach ($routes as [$method, $url]) {
        $this->{$method}($url, ['question' => 'Hi'])->assertRedirect('/login');
    }

    $staff = $this->portalMember(CompanyRole::Staff);

    foreach ($routes as [$method, $url]) {
        $this->actingAs($staff)->{$method}($url, ['question' => 'Hi'])->assertForbidden();
    }

    expect($this->fake->requests)->toBe([]);
});

test('without an API key the panel says AI is not set up yet and no question reaches a model', function () {
    FakeAiClient::install()->notConfigured();

    $this->actingAs($this->owner)->getJson('/app/assistant')->assertOk()
        ->assertJson(['available' => false, 'reason' => 'notConfigured', 'message' => 'AI features are not set up yet. Please contact Switch & Save support.']);

    $this->actingAs($this->owner)->postJson('/app/assistant/ask', ['question' => 'How are sales?'])
        ->assertStatus(503)->assertJson(['reason' => 'notConfigured']);

    expect(AiConversation::query()->count())->toBe(0)->and(AiUsage::query()->count())->toBe(0);
});

test('the status lists example questions for the user\'s tools, their shop and their own conversations only', function () {
    $manager = $this->portalMember(CompanyRole::Manager, $this->bradford);
    $this->fake->replyWith('Hello.')->replyWith('Hi.');
    $this->ask($manager, 'First question');
    $this->ask($this->owner, 'Owner question');

    $this->actingAs($manager)->getJson('/app/assistant')->assertOk()
        ->assertJson(['available' => true, 'shop' => 'Bradford', 'usage' => ['limit' => 2_000_000]])
        ->assertJsonPath('conversations.0.title', 'First question')
        ->assertJsonCount(1, 'conversations')
        ->assertJsonPath('examples.0', 'How did sales this week compare with last week?');
});

test('a question outside the plan or over the monthly allowance is refused before any model call', function () {
    AiUsage::query()->create(['company_id' => $this->kirkgate->id, 'feature' => 'assistant', 'model' => 'claude-sonnet-5', 'status' => 'ok',
        'total_tokens' => 2_000_000, 'cost_gbp' => '1.000000', 'latency_ms' => 1]);

    $this->actingAs($this->owner)->postJson('/app/assistant/ask', ['question' => 'Sales?'])
        ->assertStatus(503)->assertJson(['reason' => 'budgetExhausted', 'message' => "You have used this month's AI allowance. It resets on 1 October 2026."]);

    $this->other->forceFill(['plan_id' => $this->aiPlan([Feature::Assist], 'no-questions')->id])->save();
    $otherOwner = $this->portalMember(CompanyRole::Owner, company: $this->other);
    $this->actingAs($otherOwner)->postJson('/app/assistant/ask', ['question' => 'Sales?'])->assertStatus(503)->assertJson(['reason' => 'notInPlan']);

    expect($this->fake->requests)->toBe([]);
});

test('every model call is metered and the month\'s use shows on the billing page', function () {
    $this->fake->usage(1000, 200)->callTool('get_sales', ['period' => 'today'])->replyWith('£9.06 net.');
    $this->ask($this->owner, 'Sales today?');

    expect(AiUsage::query()->where('company_id', $this->kirkgate->id)->sum('total_tokens'))->toBe(2400);

    $this->actingAs($this->owner)->get('/app/billing')->assertOk()->assertInertia(fn (Assert $page) => $page
        ->where('aiUsage.used', 2400)
        ->where('aiUsage.limit', 2_000_000)
        ->where('aiUsage.calls', 2)
        ->where('aiUsage.included', true)
        ->where('aiUsage.resetsOn', '1 October 2026')
        ->where('aiUsage.byFeature.0.label', 'AI assistant')
        ->where('aiUsage.byPerson.0.tokens', 2400));
});

test('history is per user: shown, continued, and deleted one by one or all at once; others get 404', function () {
    $this->fake->callTool('get_sales', ['period' => 'today'])->replyWith('£13.59 net today.')->replyWith('Leeds took £4.53.');
    $first = $this->ask($this->owner, 'Sales today?')['done'];
    $id = $first['conversation']['id'];
    $second = $this->ask($this->owner, 'And Leeds?', $id)['done'];

    expect($second['conversation']['id'])->toBe($id);

    $this->actingAs($this->owner)->getJson("/app/assistant/conversations/{$id}")->assertOk()
        ->assertJsonCount(2, 'turns')
        ->assertJsonPath('turns.0.question', 'Sales today?')
        ->assertJsonPath('turns.0.answer', '£13.59 net today.')
        ->assertJsonPath('turns.0.links.0.href', '/app/reports/sales?period=custom&from=2026-09-23&to=2026-09-23&compare=previousPeriod')
        ->assertJsonPath('turns.1.answer', 'Leeds took £4.53.');

    $manager = $this->portalMember(CompanyRole::Manager);
    $otherOwner = $this->portalMember(CompanyRole::Owner, company: $this->other);

    foreach ([$manager, $otherOwner] as $intruder) {
        $this->actingAs($intruder)->getJson("/app/assistant/conversations/{$id}")->assertNotFound();
        $this->actingAs($intruder)->deleteJson("/app/assistant/conversations/{$id}")->assertNotFound();
        $this->actingAs($intruder)->postJson('/app/assistant/ask', ['question' => 'More', 'conversationId' => $id])->assertNotFound();
        $this->actingAs($intruder)->deleteJson('/app/assistant/conversations')->assertOk()->assertJson(['deleted' => 0]);
    }

    $this->actingAs($this->owner)->deleteJson("/app/assistant/conversations/{$id}")->assertOk();
    expect(AiConversation::query()->count())->toBe(0)->and(AiMessage::query()->count())->toBe(0)
        ->and(AiUsage::query()->count())->toBe(3); // billing keeps the usage

    $this->fake->replyWith('One.')->replyWith('Two.');
    $this->ask($this->owner, 'One?');
    $this->ask($this->owner, 'Two?');
    $this->actingAs($this->owner)->deleteJson('/app/assistant/conversations')->assertOk()->assertJson(['deleted' => 2]);
});

test('questions outside the business are declined, and the decline is never kept in context', function () {
    $this->fake->refuse();

    $done = $this->ask($this->owner, 'Write me a poem about the sea')['done'];

    expect($done['answer'])->toBe(RunAssistant::REFUSAL_TEXT)
        ->and($done['refused'])->toBeTrue()
        ->and($done['links'])->toBe([])
        ->and(AiMessage::query()->where('in_context', true)->count())->toBe(0);
});

test('email addresses, phone numbers and card numbers are taken out of the question before it is stored or sent', function () {
    $this->fake->replyWith('I cannot look people up by contact details.');

    $this->ask($this->owner, 'Does jo.bloggs@example.com on 07700 900123 owe us? Card 4111 1111 1111 1111. Sales up £1,234.50?');

    $sent = json_encode($this->fake->requests[0]->messages, JSON_UNESCAPED_UNICODE);
    expect($sent)->not->toContain('jo.bloggs')->not->toContain('07700')->not->toContain('4111 1111')
        ->toContain('[email removed]')->toContain('[phone removed]')->toContain('[number removed]')->toContain('£1,234.50')
        ->and(json_encode(AiMessage::query()->pluck('content')))->not->toContain('jo.bloggs');
});

test('an unhandled failure mid-answer is a friendly error event, not a broken stream', function () {
    $this->fake->failWith(AiUnavailable::rateLimited());

    $reply = $this->ask($this->owner, 'Sales?');

    expect($reply['done'])->toBeNull()
        ->and($reply['error'])->toMatchArray(['message' => 'The AI service is busy. Please try again in a minute.', 'reason' => 'rateLimited'])
        ->and(AiConversation::query()->count())->toBe(0);
});
