<?php

use App\Domain\Ai\Actions\CancelAiAction;
use App\Domain\Ai\Actions\ConfirmAiAction;
use App\Domain\Ai\Actions\RunAssistant;
use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiActionFailed;
use App\Domain\Ai\Exceptions\AiActionNotConfirmable;
use App\Domain\Ai\Models\AiMessage;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Tests\Feature\Ai\AiTestHelpers;

uses(AiTestHelpers::class);

beforeEach(fn () => $this->installFakeAi());

/**
 * Ask the assistant to rename the company's first branch; returns [context, proposal, branch].
 *
 * @return array{0: AiContext, 1: AiPendingAction, 2: Branch}
 */
function proposeRename(object $test, Company $company, string $newName = 'High Street', CompanyRole $role = CompanyRole::Manager): array
{
    $context = $test->userContext($company, $role);
    $branch = $test->branchOf($company);

    $test->fake->callTool('rename_branch', ['branch_id' => $branch->id, 'new_name' => "  {$newName}  "])
        ->replyWith("I have prepared the change. Please confirm renaming to {$newName}.");

    $reply = app(RunAssistant::class)->handle($context, "Rename my shop to {$newName}");

    expect($reply->proposals)->toHaveCount(1);

    return [$context, $reply->proposals[0], $branch];
}

test('a write tool only creates a proposal and changes nothing', function () {
    $company = $this->aiCompany();
    [$context, $proposal, $branch] = proposeRename($this, $company);

    expect($branch->fresh()->name)->toBe('Main shop')
        ->and($proposal->status)->toBe(PendingActionStatus::Pending)
        ->and($proposal->tool)->toBe('rename_branch')
        ->and($proposal->input)->toBe(['branch_id' => $branch->id, 'new_name' => 'High Street'])
        ->and($proposal->preview)->toBe('Rename branch "Main shop" (MAIN) to "High Street".')
        ->and($proposal->company_id)->toBe($company->id)
        ->and($proposal->user_id)->toBe($context->userId())
        ->and($proposal->expires_at->diffInMinutes(now()->addMinutes(15)))->toBeLessThan(1);

    $result = $this->fake->toolResultsIn()[0];
    expect($result['is_error'])->toBeFalse()
        ->and($result['content'])->toContain('"status":"awaitingConfirmation"')
        ->and($result['content'])->toContain('"actionId":"'.$proposal->id.'"')
        ->and($result['content'])->toContain('Nothing has changed yet');

    $audit = AuditLog::query()->where('action', 'ai.action_proposed')->sole();
    expect($audit->subject_id)->toBe($proposal->id)
        ->and($audit->company_id)->toBe($company->id)
        ->and($audit->actor_id)->toBe((string) $context->userId());
});

test('confirming runs the real action once, with audit', function () {
    $company = $this->aiCompany();
    [$context, $proposal, $branch] = proposeRename($this, $company);

    $done = app(ConfirmAiAction::class)->handle($proposal->id, $context);

    expect($branch->fresh()->name)->toBe('High Street')
        ->and($done->status)->toBe(PendingActionStatus::Confirmed)
        ->and($done->confirmed_at)->not->toBeNull()
        ->and($done->executed_at)->not->toBeNull()
        ->and($done->result)->toBe(['branchId' => $branch->id, 'code' => 'MAIN', 'name' => 'High Street'])
        ->and(AuditLog::query()->where('action', 'branch.updated')->count())->toBe(1)
        ->and(AuditLog::query()->where('action', 'ai.action_confirmed')->where('subject_id', $proposal->id)->count())->toBe(1);

    expect(fn () => app(ConfirmAiAction::class)->handle($proposal->id, $context))
        ->toThrow(AiActionNotConfirmable::class, 'already been confirmed');
    expect(AuditLog::query()->where('action', 'branch.updated')->count())->toBe(1);

    // The conversation learns the outcome.
    $note = AiMessage::query()->orderByDesc('position')->first();
    expect($note->content[0]['text'])->toContain('confirmed this change and it is done');
});

test('an expired proposal cannot be confirmed and is marked expired', function () {
    $company = $this->aiCompany();
    [$context, $proposal, $branch] = proposeRename($this, $company);

    $this->travel(16)->minutes();

    expect(fn () => app(ConfirmAiAction::class)->handle($proposal->id, $context))
        ->toThrow(AiActionNotConfirmable::class, 'expired');
    expect($proposal->fresh()->status)->toBe(PendingActionStatus::Expired)
        ->and($branch->fresh()->name)->toBe('Main shop');
});

test('a cancelled proposal cannot be confirmed', function () {
    $company = $this->aiCompany();
    [$context, $proposal, $branch] = proposeRename($this, $company);

    $cancelled = app(CancelAiAction::class)->handle($proposal->id, $context);

    expect($cancelled->status)->toBe(PendingActionStatus::Cancelled)
        ->and(AuditLog::query()->where('action', 'ai.action_cancelled')->count())->toBe(1);
    expect(fn () => app(ConfirmAiAction::class)->handle($proposal->id, $context))
        ->toThrow(AiActionNotConfirmable::class, 'cancelled');
    expect($branch->fresh()->name)->toBe('Main shop');
});

test('only the proposer can confirm or cancel', function () {
    $company = $this->aiCompany();
    [, $proposal, $branch] = proposeRename($this, $company);
    $colleague = $this->userContext($company, CompanyRole::Owner);
    $otherCompany = $this->userContext($this->aiCompany(name: 'Other Ltd', branchCode: 'OTH'));

    expect(fn () => app(ConfirmAiAction::class)->handle($proposal->id, $colleague))->toThrow(AiAccessDenied::class)
        ->and(fn () => app(ConfirmAiAction::class)->handle($proposal->id, $otherCompany))->toThrow(AiAccessDenied::class)
        ->and(fn () => app(CancelAiAction::class)->handle($proposal->id, $otherCompany))->toThrow(AiAccessDenied::class);

    expect($proposal->fresh()->status)->toBe(PendingActionStatus::Pending)
        ->and($branch->fresh()->name)->toBe('Main shop');
});

test('losing the ability after proposing blocks the confirmation', function () {
    $company = $this->aiCompany();
    [$context, $proposal, $branch] = proposeRename($this, $company);

    $company->users()->updateExistingPivot($context->userId(), ['role' => CompanyRole::Staff->value]);

    expect(fn () => app(ConfirmAiAction::class)->handle($proposal->id, $context))->toThrow(AiAccessDenied::class);
    expect($proposal->fresh()->status)->toBe(PendingActionStatus::Pending)
        ->and($branch->fresh()->name)->toBe('Main shop');
});

test('a proposal whose record is gone fails cleanly and is audited', function () {
    $company = $this->aiCompany();
    [$context, $proposal, $branch] = proposeRename($this, $company);

    Branch::withoutCompanyScope()->whereKey($branch->id)->delete();

    expect(fn () => app(ConfirmAiAction::class)->handle($proposal->id, $context))
        ->toThrow(AiActionFailed::class, 'not found');
    expect($proposal->fresh()->status)->toBe(PendingActionStatus::Failed)
        ->and(AuditLog::query()->where('action', 'ai.action_failed')->count())->toBe(1);
});

test('renaming to the same name does not create a proposal', function () {
    $company = $this->aiCompany();
    $context = $this->userContext($company);
    $branch = $this->branchOf($company);
    $this->fake->callTool('rename_branch', ['branch_id' => $branch->id, 'new_name' => 'Main shop'])->replyWith('Already named that.');

    $reply = app(RunAssistant::class)->handle($context, 'Rename to Main shop');

    expect($reply->proposals)->toBe([])
        ->and($this->fake->toolResultsIn()[0]['content'])->toContain('noChange');
});
