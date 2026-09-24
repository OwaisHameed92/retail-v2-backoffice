# AI foundation (module 5.1)

The engine that the AI features (5.2 portal assistant, 5.3 morning summary, 5.4 reorder suggestions, 5.5 invoice
import, 5.6 anomaly alerts, 5.7 admin AI) plug into. Code: `app/Domain/Ai`. Config: `config/ai.php`. Decisions and
reasons: `docs/DECISIONS.md` → "AI foundation".

## Setup

```dotenv
ANTHROPIC_API_KEY=sk-ant-...   # empty = every AI feature says "AI features are not set up yet"
AI_ENABLED=true                # kill switch
AI_MONTHLY_TOKENS=2000000      # default monthly budget per company
```

Try it against the real API (metered like any other call):

```bash
php artisan ai:ask "Khan Mini Mart" "How many tills do I have?"                      # read-only system job
php artisan ai:ask 01J9Z... "Rename Leeds to Leeds Kirkgate" --user=owner@khan.test   # proposes, never confirms
```

## The pieces

| Piece | What it does |
|---|---|
| `AiContext` | Who is asking: `forUser($user, $company)`, `forSystem($company, $feature)` (jobs, read tools only), `forAdmin($admin)`. Re-reads the user's role from the database on every check |
| `Contracts\AiClient` | Provider. `AnthropicAiClient` (official SDK, retries, timeouts, streaming, fallbacks); `Testing\FakeAiClient` for tests |
| `Actions\CallModel` | One metered call: `AiGate` (switch → key → company status → plan feature → budget) → client → `ai_usage` row. Use it for single-shot features |
| `Actions\RunAssistant` | The tool-use loop for conversations (max `ai.max_steps` model calls per question) |
| `Tools\ToolRegistry` / `ToolExecutor` | Which tools an actor is offered; runs a call safely (ability, validation, tenant scope, proposals, redaction, `<tool_data>` wrapping) |
| `Actions\ConfirmAiAction` / `CancelAiAction` | The only way a proposed change is executed or declined |
| `Support\AiBudget`, `AiCost` | Monthly token allowance; cost in pounds (6 dp) |
| `Support\SystemPrompt` | Frozen tenant/admin prompts + the per-conversation `<context>` block |

## Using it from a feature

Conversation (5.2, 5.7):

```php
$context = AiContext::forUser($request->user(), $currentCompany->require());
$reply = app(RunAssistant::class)->handle($context, $question, $conversation, onText: fn ($t) => /* stream */);
// $reply->text, $reply->proposals (AiPendingAction[] to show with Confirm / Cancel), $reply->usage, $reply->costGbp
```

Single call (5.3, 5.6): build an `AiRequest` with `AiSettings::modelFor($feature)`, `SystemPrompt::blocks()` (or
your own frozen prompt with `cache_control` on the last block) and pass it to `CallModel::handle($context, $request)`.
Put anything that changes per call (dates, figures) in the messages, never in the system prompt.

Errors to handle in controllers and jobs:

| Exception | Meaning | Show |
|---|---|---|
| `AiUnavailable` (`reason`: disabled, notConfigured, companyInactive, notInPlan, budgetExhausted, rateLimited, providerError) | No call could be made | `getMessage()` (safe for users) |
| `AiAccessDenied` | Wrong company/user for a conversation or proposal, or missing ability | 404 / 403 |
| `AiActionNotConfirmable` | Proposal already confirmed, cancelled, expired or failed | `getMessage()` |
| `AiActionFailed` | The real Action refused or failed on confirm | `getMessage()` |

## Adding a tool

1. Create a class in `app/Domain/Ai/Tools` implementing `Contracts\AiTool` (read) or `Contracts\AiWriteTool` (write).
2. Add it to `config('ai.tools')`.
3. Write tests with `FakeAiClient` (below): allowed role, refused role, another company's ids, and for writes the
   proposal → confirm path.

```php
final class GetBranchSales implements AiTool
{
    public function name(): string { return 'get_branch_sales'; }            // snake_case, stable
    public function description(): string { return 'Sales totals for one branch and date range...'; }
    public function inputSchema(): array {
        return ['type' => 'object', 'properties' => [
            'branch_id' => ['type' => 'string'], 'from' => ['type' => 'string', 'format' => 'date'],
        ], 'required' => ['branch_id', 'from'], 'additionalProperties' => false];
    }
    public function rules(): array { return ['branch_id' => ['required', new ValidUlid], 'from' => ['required', 'date']]; }
    public function requiredAbility(): Ability { return Ability::SalesView; }
    public function kind(): ToolKind { return ToolKind::Read; }
    public function audience(): ToolAudience { return ToolAudience::Tenant; }
    public function handle(array $input, AiContext $context): array { /* scoped queries, return plain data */ }
}
```

Rules for tool authors:

- **Never take a company id.** Tenant tools already run inside `CurrentCompany::runAs(actor's company)`; query tenant
  models normally and an id from another company is simply not found.
- **Return only what the model needs.** No emails, phones, addresses, keys. Money as strings in pounds ("12.50").
  Results are redacted and cut at `ai.max_tool_result_chars`, but do not rely on that.
- **Call Actions, not models, for anything that changes data** (in `execute()` of a write tool).
- Keep descriptions factual; they are part of the cached prompt prefix (changing one re-caches once).
- Admin tools: `audience()` = `Admin`, `requiredAbility()` = an `AdminRole` constant (e.g. `AdminRole::TENANTS_VIEW`).

## Read vs write, and the confirm flow

```
model calls rename_branch ─► ToolExecutor: ability? input valid? (in company scope)
                                  │
                         tool->handle() returns AiProposal(preview, input)   ← nothing changes
                                  │
                      ai_pending_actions row (pending, expires in 15 min) + audit ai.action_proposed
                                  │
          tool result to the model: {status: awaitingConfirmation, actionId, preview}
                                  │
       UI shows preview with Confirm / Cancel ──► ConfirmAiAction::handle($actionId, $context)
                                                    - proposer only, row locked, pending → confirmed (runs once)
                                                    - expired → marked expired, refused
                                                    - ability re-checked, input re-validated
                                                    - tool->execute() → real Action (own audit)
                                                    - audit ai.action_confirmed / ai.action_failed
                                                    - <app_event> note appended to the conversation
```

Write tools may also return plain data instead of a proposal (e.g. "already called that").

## Metering and budgets

- Every model call writes one `ai_usage` row (also errors and refusals): tokens (input, output, cache read, cache
  write, total), `cost_gbp` (6 dp, from `config('ai.pricing')` × `ai.usd_to_gbp`), latency, feature, model.
- A company needs the plan feature: `aiAssistant` for the assistant, `aiInsights` for the other tenant features.
- Monthly budget = total tokens this calendar month (Europe/London): `ai.budgets.plans.<plan code>` or
  `ai.budgets.default_monthly_tokens`. Admin AI uses `ai.budgets.admin_monthly_tokens`.
- `AiBudget::remaining($context)` and `AiGate::isAvailable($context)` let the UI hide or explain AI features.
- Retention: conversations (and their messages) and proposals 90 days, usage 24 months (`model:prune`, daily 02:30).

## Testing

No test may touch the network. Bind the fake and script the model:

```php
$fake = FakeAiClient::install()                    // binds AiClient
    ->callTool('get_company_overview')             // reply 1: a tool call (stop_reason tool_use)
    ->callTools([['name' => 'a', 'input' => []], ['name' => 'b', 'input' => []]])  // parallel calls
    ->replyWith('You have 2 tills.');              // final answer
$fake->usage(1000, 200);                           // tokens for following replies
$fake->refuse(); $fake->failWith(AiUnavailable::rateLimited()); $fake->notConfigured();
$fake->push(fn (AiRequest $r) => new AiResponse(...));   // reply based on the request

$fake->requests;          // every AiRequest sent
$fake->lastRequest()->toolNames();
$fake->toolResultsIn();   // tool_result blocks sent back in the last request
```

`tests/Feature/Ai/AiTestHelpers.php` has `aiPlan()`, `aiCompany()`, `member()`, `userContext()`, `branchOf()`.
`AnthropicAiClientTest` shows how to test the real client against a Guzzle `MockHandler` (request shape, retries,
streaming).
