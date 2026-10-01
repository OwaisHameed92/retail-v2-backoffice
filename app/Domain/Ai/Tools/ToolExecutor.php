<?php

namespace App\Domain\Ai\Tools;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Contracts\AiTool;
use App\Domain\Ai\Data\AiProposal;
use App\Domain\Ai\Data\ToolCallResult;
use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Ai\Enums\ToolAudience;
use App\Domain\Ai\Exceptions\AiActionFailed;
use App\Domain\Ai\Models\AiConversation;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Ai\Support\AiRedactor;
use App\Domain\Ai\Support\AiSettings;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Tenancy\CurrentCompany;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Runs one tool call from the model, safely:
 *
 * 1. the tool must exist and the actor must be allowed to use it (ability re-read from the database);
 * 2. input keys must be in the schema and pass the tool's rules;
 * 3. tenant tools run inside CurrentCompany::runAs(actor's company, role, one-shop limit), so ids from another
 *    company are not found and a one-shop user's tools stay on their shop;
 * 4. write tools only produce a proposal, stored as an AiPendingAction (audited), never a change;
 * 5. the result is redacted and wrapped as data (`<tool_data>`), so text inside it cannot pose as instructions.
 *
 * Failures become `is_error` tool results with a safe message; the model can recover or tell the user.
 */
final class ToolExecutor
{
    public function __construct(
        private readonly ToolRegistry $registry,
        private readonly CurrentCompany $tenancy,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @param  array{id: string, name: string, input: array<string, mixed>}  $call
     */
    public function run(AiContext $context, array $call, ?AiConversation $conversation = null): ToolCallResult
    {
        $tool = $this->registry->find($call['name']);

        if ($tool === null) {
            return $this->error($call, "There is no tool called '{$call['name']}'.");
        }

        if (! $this->registry->allows($tool, $context)) {
            return $this->error($call, 'The user does not have permission to use this tool. Tell them so; do not try another way.');
        }

        try {
            $input = $this->validate($tool, $call['input']);
            $outcome = $this->inScope($tool, $context, fn () => $tool->handle($input, $context));
        } catch (ValidationException $e) {
            return $this->error($call, 'Invalid input: '.implode(' ', $e->validator->errors()->all()));
        } catch (ModelNotFoundException) {
            return $this->error($call, 'Not found in this business.');
        } catch (AiActionFailed $e) {
            return $this->error($call, $e->getMessage());
        } catch (Throwable $e) {
            report($e);

            return $this->error($call, 'The tool failed. Tell the user it did not work and suggest trying again later.');
        }

        if ($outcome instanceof AiProposal) {
            $action = $this->propose($tool, $outcome, $context, $conversation);

            return new ToolCallResult($call['id'], $call['name'], $this->wrap($call['name'], [
                'status' => 'awaitingConfirmation',
                'actionId' => $action->id,
                'preview' => $action->preview,
                'expiresAt' => ApiDate::format($action->expires_at),
                'note' => 'Nothing has changed yet. The user must confirm this change in the app. Do not say it is done.',
            ]), pendingAction: $action);
        }

        return new ToolCallResult($call['id'], $call['name'], $this->wrap($call['name'], $outcome));
    }

    /**
     * Validate input against the schema's property names and the tool's rules.
     *
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    public function validate(AiTool $tool, array $input): array
    {
        $allowed = array_keys((array) ($tool->inputSchema()['properties'] ?? []));
        $unknown = array_diff(array_map('strval', array_keys($input)), $allowed);

        $validator = Validator::make($input, $tool->rules());
        $validator->after(function ($validator) use ($unknown) {
            foreach ($unknown as $key) {
                $validator->errors()->add($key, "The field {$key} is not allowed.");
            }
        });

        /** @var array<string, mixed> */
        return $validator->validate();
    }

    /**
     * Run a callback in the tool's scope: tenant tools inside runAs(actor's company, actor's role).
     *
     * @template T
     *
     * @param  callable(): T  $callback
     * @return T
     */
    public function inScope(AiTool $tool, AiContext $context, callable $callback): mixed
    {
        if ($tool->audience() === ToolAudience::Admin || $context->company === null) {
            return $callback();
        }

        $role = $context->currentRole();
        $branchId = $context->restrictedBranchId();

        // runAs() clears the one-shop limit; put the user's back so tools (and the queries they reuse) keep to it.
        return $this->tenancy->runAs($context->company, function ($company) use ($callback, $role, $branchId) {
            $this->tenancy->set($company, $role, $branchId);

            return $callback();
        }, $role);
    }

    private function propose(AiTool $tool, AiProposal $proposal, AiContext $context, ?AiConversation $conversation): AiPendingAction
    {
        $action = AiPendingAction::query()->create([
            'company_id' => $context->companyId(),
            'user_id' => $context->userId(),
            'admin_id' => $context->adminId(),
            'conversation_id' => $conversation?->id,
            'tool' => $tool->name(),
            'input' => $proposal->input,
            'preview' => mb_substr($proposal->preview, 0, 1000),
            'status' => PendingActionStatus::Pending,
            'expires_at' => now()->addMinutes(AiSettings::pendingActionTtlMinutes()),
        ]);

        $this->audit->handle('ai.action_proposed', $action, after: [
            'tool' => $action->tool,
            'input' => $action->input,
            'preview' => $action->preview,
        ], actor: $context->actor(), companyId: $context->companyId());

        return $action;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function wrap(string $name, array $data): string
    {
        $flags = JSON_HEX_TAG | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION;
        $json = (string) json_encode(AiRedactor::data($data), $flags);
        $max = AiSettings::maxToolResultChars();

        if (strlen($json) > $max) {
            $json = (string) json_encode([
                'truncated' => true,
                'note' => 'The result was too long and was cut. Say that some data is missing.',
                'partial' => mb_strcut($json, 0, $max - 200),
            ], $flags);
        }

        return "<tool_data tool=\"{$name}\">\n{$json}\n</tool_data>";
    }

    /**
     * @param  array{id: string, name: string, input: array<string, mixed>}  $call
     */
    private function error(array $call, string $message): ToolCallResult
    {
        return new ToolCallResult($call['id'], $call['name'], $message, isError: true);
    }
}
