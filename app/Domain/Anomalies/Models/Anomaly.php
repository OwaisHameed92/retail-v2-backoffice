<?php

namespace App\Domain\Anomalies\Models;

use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One unusual-activity finding (module 6.6): what was seen, against which baseline, where to look, and what the
 * business decided. Tenant-owned; the detection job writes it with `withoutCompanyScope()` for a known company.
 * Kept {@see self::KEEP_MONTHS} months (`model:prune`).
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $branch_id
 * @property string|null $register_id
 * @property AnomalyKind $kind
 * @property AnomalySeverity $severity
 * @property bool $staff_level
 * @property string|null $subject_id
 * @property string|null $subject_name
 * @property string $dedupe_key
 * @property string $group_key
 * @property string $trading_day London trading day, Y-m-d (no date cast: compared as text, like MorningSummary)
 * @property CarbonImmutable $period_start
 * @property CarbonImmutable $period_end
 * @property string $title
 * @property string $summary
 * @property list<array{label: string, value: string, usual?: string|null, peers?: string|null}> $facts
 * @property list<array{label: string, href: string}>|null $links
 * @property string $score
 * @property int $occurrences
 * @property AnomalyStatus $status
 * @property string|null $status_reason
 * @property int|null $status_by
 * @property CarbonImmutable|null $status_at
 * @property CarbonImmutable $detected_at
 * @property CarbonImmutable|null $notified_at
 * @property string|null $explanation
 * @property CarbonImmutable|null $explained_at
 * @property CarbonImmutable|null $created_at
 */
class Anomaly extends Model
{
    use BelongsToCompany, HasPortalUlid, MassPrunable;

    public const KEEP_MONTHS = 24;

    /** @var list<string> */
    protected $fillable = [
        'company_id', 'branch_id', 'register_id', 'kind', 'severity', 'staff_level', 'subject_id', 'subject_name',
        'dedupe_key', 'group_key', 'trading_day', 'period_start', 'period_end', 'title', 'summary', 'facts', 'links',
        'score', 'occurrences', 'status', 'status_reason', 'status_by', 'status_at', 'detected_at', 'notified_at',
        'explanation', 'explained_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => AnomalyKind::class,
            'severity' => AnomalySeverity::class,
            'status' => AnomalyStatus::class,
            'staff_level' => 'boolean',
            'period_start' => 'immutable_datetime',
            'period_end' => 'immutable_datetime',
            'status_at' => 'immutable_datetime',
            'detected_at' => 'immutable_datetime',
            'notified_at' => 'immutable_datetime',
            'explained_at' => 'immutable_datetime',
            'created_at' => 'immutable_datetime',
            'facts' => 'array',
            'links' => 'array',
            'occurrences' => 'integer',
            'status_by' => 'integer',
        ];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::withoutCompanyScope()->where('created_at', '<', now()->subMonths(self::KEEP_MONTHS));
    }
}
