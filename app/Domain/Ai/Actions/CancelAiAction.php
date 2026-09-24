<?php

namespace App\Domain\Ai\Actions;

use App\Domain\Ai\AiContext;
use App\Domain\Ai\Enums\PendingActionStatus;
use App\Domain\Ai\Exceptions\AiAccessDenied;
use App\Domain\Ai\Exceptions\AiActionNotConfirmable;
use App\Domain\Ai\Models\AiPendingAction;
use App\Domain\Shared\Actions\RecordAudit;
use Illuminate\Support\Facades\DB;

/**
 * The proposer declines a proposed change. Only pending, unexpired proposals can be cancelled. Audited.
 */
final class CancelAiAction
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws AiAccessDenied|AiActionNotConfirmable
     */
    public function handle(string $actionId, AiContext $context): AiPendingAction
    {
        // Refusals are thrown after the transaction so an "expired" mark is kept, not rolled back.
        [$action, $refusal] = DB::transaction(function () use ($actionId, $context) {
            $action = AiPendingAction::query()->ownedBy($context)->whereKey($actionId)->lockForUpdate()->first();

            if ($action === null) {
                return [null, AiAccessDenied::notYours()];
            }

            if ($action->status === PendingActionStatus::Pending && $action->hasExpired()) {
                $action->forceFill(['status' => PendingActionStatus::Expired])->save();
            }

            if ($action->status !== PendingActionStatus::Pending) {
                return [$action, new AiActionNotConfirmable($action->status)];
            }

            $action->forceFill(['status' => PendingActionStatus::Cancelled, 'cancelled_at' => now()])->save();

            return [$action, null];
        });

        if ($refusal !== null || $action === null) {
            throw $refusal ?? AiAccessDenied::notYours();
        }

        $this->audit->handle('ai.action_cancelled', $action, meta: ['tool' => $action->tool],
            actor: $context->actor(), companyId: $action->company_id);

        return $action;
    }
}
