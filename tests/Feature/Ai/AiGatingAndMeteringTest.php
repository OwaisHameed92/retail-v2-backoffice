<?php

use App\Domain\Admin\Models\Admin;
use App\Domain\Ai\Actions\CallModel;
use App\Domain\Ai\Actions\RunAssistant;
use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiClient;
use App\Domain\Ai\Data\AiRequest;
use App\Domain\Ai\Data\AiTokenUsage;
use App\Domain\Ai\Enums\AiFeature;
use App\Domain\Ai\Enums\AiUnavailableReason;
use App\Domain\Ai\Enums\AiUsageStatus;
use App\Domain\Ai\Exceptions\AiUnavailable;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiMessage;
use App\Domain\Ai\Models\AiUsage;
use App\Domain\Ai\Support\AiBudget;
use App\Domain\Ai\Support\AiCost;
use App\Domain\Ai\Support\AiGate;
use App\Domain\Ai\Support\AiSettings;
use App\Domain\Ai\Support\SystemPrompt;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Tenancy\Enums\CompanyStatus;
use Tests\Feature\Ai\AiTestHelpers;

uses(AiTestHelpers::class);

beforeEach(fn () => $this->installFakeAi());

function expectUnavailable(Closure $call, AiUnavailableReason $reason, string $message): void
{
    try {
        $call();
    } catch (AiUnavailable $e) {
        expect($e->reason)->toBe($reason)->and($e->getMessage())->toContain($message);

        return;
    }

    test()->fail('AiUnavailable was not thrown.');
}

test('with no API key the real client reports not configured and nothing is stored', function () {
    app()->forgetInstance(AiClient::class);
    config(['ai.anthropic.api_key' => '']);
    $context = $this->userContext($this->aiCompany());

    expect(app(AiClient::class)->isConfigured())->toBeFalse();
    expectUnavailable(fn () => app(RunAssistant::class)->handle($context, 'Hi'), AiUnavailableReason::NotConfigured, 'not set up yet');
    expect(AiConversation::query()->count())->toBe(0)->and(AiUsage::query()->count())->toBe(0);
});

test('a not configured client is reported gracefully', function () {
    $this->fake->notConfigured();
    $context = $this->userContext($this->aiCompany());

    expect(app(AiGate::class)->isAvailable($context))->toBeFalse();
    expectUnavailable(fn () => app(RunAssistant::class)->handle($context, 'Hi'), AiUnavailableReason::NotConfigured, 'contact Switch & Save support');
    expect($this->fake->requests)->toBe([]);
});

test('the kill switch turns every AI feature off', function () {
    config(['ai.enabled' => false]);
    $context = $this->userContext($this->aiCompany());

    expectUnavailable(fn () => app(RunAssistant::class)->handle($context, 'Hi'), AiUnavailableReason::Disabled, 'switched off');
});

test('a plan without the AI assistant feature is refused', function () {
    $plan = $this->aiPlan([Feature::StockControl, Feature::AiInsights], code: 'no-assistant');
    $context = $this->userContext($this->aiCompany($plan));

    expectUnavailable(fn () => app(RunAssistant::class)->handle($context, 'Hi'), AiUnavailableReason::NotInPlan, 'does not include the AI assistant');

    // The same plan allows insight features.
    $this->fake->replyWith('Summary.');
    $insights = $context->withFeature(AiFeature::MorningSummary);
    expect(app(AiGate::class)->isAvailable($insights))->toBeTrue();
});

test('suspended companies cannot use AI', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $company->forceFill(['status' => CompanyStatus::Suspended])->save();

    expectUnavailable(fn () => app(RunAssistant::class)->handle(AiContext::forUser($context->user, $company->fresh()), 'Hi'),
        AiUnavailableReason::CompanyInactive, 'on hold');
});

test('the monthly budget is enforced per company and resets next month', function () {
    config(['ai.budgets.default_monthly_tokens' => 1000]);
    $company = $this->aiCompany();
    $other = $this->aiCompany(name: 'Other Ltd', branchCode: 'OTH');
    $context = $this->userContext($company);

    AiUsage::query()->create(['company_id' => $company->id, 'feature' => 'assistant', 'model' => 'claude-opus-5',
        'status' => 'ok', 'total_tokens' => 1000, 'cost_gbp' => '0']);

    expect(app(AiBudget::class)->remaining($context))->toBe(0)
        ->and(app(AiBudget::class)->remaining($this->userContext($other)))->toBe(1000);
    expectUnavailable(fn () => app(RunAssistant::class)->handle($context, 'Hi'), AiUnavailableReason::BudgetExhausted, "used this month's AI allowance");

    $this->travelTo(now('Europe/London')->startOfMonth()->addMonth()->addHour());
    expect(app(AiBudget::class)->remaining($context))->toBe(1000);
});

test('a plan code can have its own budget', function () {
    $plan = $this->aiPlan(code: 'starter');
    config(['ai.budgets.plans' => ['starter' => 0]]);
    $context = $this->userContext($this->aiCompany($plan));

    expectUnavailable(fn () => app(RunAssistant::class)->handle($context, 'Hi'), AiUnavailableReason::BudgetExhausted, 'resets on');
});

test('every call is metered with tokens, cost in pounds and latency', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $this->fake->usage(1000, 500, 2000, 400)->callTool('get_company_overview')->replyWith('Done.');

    $reply = app(RunAssistant::class)->handle($context, 'Overview');

    $rows = AiUsage::query()->orderBy('id')->get();
    expect($rows)->toHaveCount(2);

    $row = $rows->first();
    // 1000 x $5 + 500 x $25 + 2000 x $0.50 + 400 x $6.25 = $0.021 per call; x 0.79 = £0.016590
    expect($row->company_id)->toBe($company->id)
        ->and($row->user_id)->toBe($context->userId())
        ->and($row->conversation_id)->toBe($reply->conversation->id)
        ->and($row->feature)->toBe(AiFeature::Assistant)
        ->and($row->model)->toBe('claude-opus-5')
        ->and($row->status)->toBe(AiUsageStatus::Ok)
        ->and($row->input_tokens)->toBe(1000)
        ->and($row->output_tokens)->toBe(500)
        ->and($row->cache_read_tokens)->toBe(2000)
        ->and($row->cache_write_tokens)->toBe(400)
        ->and($row->total_tokens)->toBe(3900)
        ->and($row->cost_gbp)->toBe('0.016590')
        ->and($row->latency_ms)->toBe(5)
        ->and($reply->costGbp)->toBe('0.033180')
        ->and($reply->usage->total())->toBe(7800)
        ->and(app(AiBudget::class)->usedBy($context))->toBe(7800);
});

test('the fast model is priced at its own rates', function () {
    expect(AiCost::pounds('claude-haiku-4-5', new AiTokenUsage(1_000_000, 100_000)))->toBe('1.185000');
});

test('single-shot features use the fast model through CallModel', function () {
    $company = $this->aiCompany();
    $context = AiContext::forSystem($company, AiFeature::MorningSummary);
    $this->fake->replyWith('Good morning.');

    $response = app(CallModel::class)->handle($context, new AiRequest(
        feature: AiFeature::MorningSummary,
        model: AiSettings::modelFor(AiFeature::MorningSummary),
        system: SystemPrompt::blocks($context),
        messages: [['role' => 'user', 'content' => 'Summarise yesterday.']],
    ));

    expect($response->text())->toBe('Good morning.')
        ->and($this->fake->lastRequest()->model)->toBe('claude-haiku-4-5')
        ->and(AiUsage::query()->sole()->feature)->toBe(AiFeature::MorningSummary)
        ->and(AiUsage::query()->sole()->user_id)->toBeNull();
});

test('a provider failure on the first call is metered and leaves no conversation', function () {
    $context = $this->userContext($this->aiCompany());
    $this->fake->failWith(AiUnavailable::rateLimited());

    expectUnavailable(fn () => app(RunAssistant::class)->handle($context, 'Hi'), AiUnavailableReason::RateLimited, 'busy');

    expect(AiConversation::query()->count())->toBe(0)
        ->and(AiUsage::query()->sole()->status)->toBe(AiUsageStatus::Error);
});

test('a provider failure mid-loop returns what happened instead of throwing', function () {
    $context = $this->userContext($this->aiCompany());
    $this->fake->callTool('get_company_overview')->failWith(AiUnavailable::providerError());

    $reply = app(RunAssistant::class)->handle($context, 'Hi');

    expect($reply->stoppedEarly)->toBeTrue()
        ->and($reply->text)->toContain('could not answer just now')
        ->and(AiMessage::query()->where('in_context', true)->count())->toBe(3);
});

test('a refusal is metered as refused', function () {
    $context = $this->userContext($this->aiCompany());
    $this->fake->refuse();

    app(RunAssistant::class)->handle($context, 'Hi');

    expect(AiUsage::query()->sole()->status)->toBe(AiUsageStatus::Refused);
});

test('admins use the admin prompt, their own pool and no tenant tools', function () {
    config(['ai.budgets.admin_monthly_tokens' => 500]);
    $admin = Admin::factory()->create();
    $context = AiContext::forAdmin($admin);
    $this->fake->replyWith('Hello staff.');

    $reply = app(RunAssistant::class)->handle($context, 'Which tenants need help?');

    $request = $this->fake->lastRequest();
    expect($request->system[0]['text'])->toBe(SystemPrompt::ADMIN)
        ->and($request->tools)->toBe([])
        ->and($reply->conversation->admin_id)->toBe($admin->id)
        ->and($reply->conversation->company_id)->toBeNull()
        ->and(AiUsage::query()->sole()->admin_id)->toBe($admin->id)
        ->and(app(AiBudget::class)->remaining($context))->toBe(380);
});

test('old conversations, proposals and usage are pruned', function () {
    $context = $this->userContext($this->aiCompany());
    $this->fake->replyWith('Hi.');
    app(RunAssistant::class)->handle($context, 'Hi');

    $this->travel(91)->days();
    $this->artisan('model:prune', ['--model' => [AiConversation::class]])->assertSuccessful();

    expect(AiConversation::query()->count())->toBe(0)
        ->and(AiMessage::query()->count())->toBe(0)
        ->and(AiUsage::query()->count())->toBe(1);
});
