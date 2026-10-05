<?php

namespace App\Domain\Anomalies\Queries;

use App\Domain\Anomalies\Models\Anomaly;
use App\Domain\Anomalies\Support\AnomalyLinks;
use App\Domain\Anomalies\Support\AnomalyVisibility;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Tenancy\CurrentCompany;
use App\Models\User;

/**
 * Props of one finding's page (module 6.6): the finding, its facts against the usual figures, the drill-down links
 * the user can open, who changed its status and when (from the audit log), and the stored AI explanation.
 */
final class AnomalyDetail
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Anomaly $anomaly, CurrentCompany $tenancy): array
    {
        $history = AuditLog::query()->where('company_id', $anomaly->company_id)
            ->where('subject_type', $anomaly->getMorphClass())->where('subject_id', $anomaly->id)
            ->where('action', 'like', 'anomaly.%')->orderByDesc('created_at')->orderByDesc('id')->limit(50)->get();
        $userIds = array_values(array_unique(array_filter([$anomaly->status_by, ...$history->where('actor_type', (new User)->getMorphClass())->pluck('actor_id')->map(fn ($id) => (int) $id)->all()])));
        $users = User::query()->whereKey($userIds)->pluck('name', 'id')->all();

        return [
            'anomaly' => [
                ...AnomalyList::row($anomaly),
                'facts' => array_map(fn (array $f) => [
                    'label' => $f['label'], 'value' => $f['value'], 'usual' => $f['usual'] ?? null, 'peers' => $f['peers'] ?? null,
                ], $anomaly->facts),
                'links' => array_values(array_filter($anomaly->links ?? [], function (array $l) use ($tenancy) {
                    $ability = AnomalyLinks::abilityFor($l['href']);

                    return $ability === null || $tenancy->can($ability);
                })),
                'periodStart' => $anomaly->period_start->utc()->format('Y-m-d\TH:i:s\Z'),
                'periodEnd' => $anomaly->period_end->utc()->format('Y-m-d\TH:i:s\Z'),
                'statusReason' => $anomaly->status_reason,
                'statusBy' => $anomaly->status_by !== null ? ($users[$anomaly->status_by] ?? 'A former user') : null,
                'statusAt' => $anomaly->status_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'explanation' => $anomaly->explanation,
            ],
            'history' => $history->map(fn (AuditLog $log) => [
                'id' => (string) $log->id,
                'action' => (string) $log->action,
                'by' => $log->actor_id !== null ? ($users[(int) $log->actor_id] ?? 'A former user') : 'System',
                'at' => $log->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
                'reason' => is_array($log->after) ? ($log->after['reason'] ?? null) : null,
            ])->values()->all(),
            'canManage' => AnomalyVisibility::canManage($tenancy->role()),
            'canExplain' => $tenancy->can('ai.use'),
        ];
    }
}
