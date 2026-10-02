<?php

namespace App\Domain\Anomalies\Actions;

use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Acknowledge a finding ("looking into it"), dismiss it with a reason, or reopen it (module 6.6). Runs in the
 * current company's scope: another business's id is simply not found. Who may do it (owners and managers, and only
 * findings they can see) is checked by the caller. Every change is audited with the reason.
 */
class ChangeAnomalyStatus
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(string $anomalyId, AnomalyStatus $to, ?string $reason, int $userId): Anomaly
    {
        return DB::transaction(function () use ($anomalyId, $to, $reason, $userId): Anomaly {
            $anomaly = Anomaly::query()->lockForUpdate()->findOrFail($anomalyId);
            $from = $anomaly->status;
            $reason = $reason !== null && trim($reason) !== '' ? trim($reason) : null;

            if ($from === $to) {
                throw ValidationException::withMessages(['status' => 'This finding is already '.strtolower($to->label()).'.']);
            }

            if ($to === AnomalyStatus::Dismissed && $reason === null) {
                throw ValidationException::withMessages(['reason' => 'Say why you are dismissing it.']);
            }

            $anomaly->forceFill([
                'status' => $to,
                'status_reason' => $to === AnomalyStatus::New ? null : $reason,
                'status_by' => $userId,
                'status_at' => CarbonImmutable::now('UTC'),
            ])->save();

            $action = match ($to) {
                AnomalyStatus::Acknowledged => 'anomaly.acknowledged',
                AnomalyStatus::Dismissed => 'anomaly.dismissed',
                AnomalyStatus::New => 'anomaly.reopened',
            };
            $this->audit->handle($action, $anomaly, ['status' => $from->value], ['status' => $to->value, 'reason' => $reason], ['title' => $anomaly->title]);

            return $anomaly;
        });
    }
}
