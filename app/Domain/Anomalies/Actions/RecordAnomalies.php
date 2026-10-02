<?php

namespace App\Domain\Anomalies\Actions;

use App\Domain\Anomalies\Data\AnomalyFinding;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Stores what the detectors found (module 6.6), never twice:
 *
 * - the same finding again (same kind, shop, subject and day; e.g. the hourly run as a gap grows) updates its row;
 * - the same kind, shop and subject on a later day within the kind's cool-down updates the open row (one more
 *   occurrence) rather than raising a new one; a dismissed row in the cool-down keeps it quiet unless the new finding
 *   is more severe than the one dismissed;
 * - anything else is a new row.
 *
 * Returns the rows that are new or became more severe (and not dismissed): those may need an alert.
 */
class RecordAnomalies
{
    /**
     * @param  list<AnomalyFinding>  $findings
     * @return list<Anomaly>
     */
    public function handle(string $companyId, array $findings, CarbonImmutable $now): array
    {
        $raised = [];

        foreach ($findings as $finding) {
            $row = DB::transaction(fn () => $this->one($companyId, $finding, $now));

            if ($row !== null) {
                $raised[] = $row;
            }
        }

        return $raised;
    }

    private function one(string $companyId, AnomalyFinding $f, CarbonImmutable $now): ?Anomaly
    {
        $query = fn () => Anomaly::withoutCompanyScope()->where('company_id', $companyId)->lockForUpdate();
        $exact = $query()->where('dedupe_key', $f->dedupeKey())->first();

        if ($exact !== null) {
            return $this->update($exact, $f, $now, false);
        }

        $coolDown = $f->kind->coolDownDays();

        if ($coolDown > 0) {
            $recent = $query()->where('group_key', $f->groupKey())
                ->where('trading_day', '>=', CarbonImmutable::parse($f->day, 'UTC')->subDays($coolDown)->toDateString())
                ->orderByDesc('trading_day')->first();

            if ($recent !== null) {
                $dismissedQuiet = $recent->status === AnomalyStatus::Dismissed && $f->severity->rank() <= $recent->severity->rank();

                if ($dismissedQuiet || $f->day <= substr($recent->trading_day, 0, 10)) {
                    return null;
                }

                if ($recent->status !== AnomalyStatus::Dismissed) {
                    return $this->update($recent, $f, $now, true);
                }
            }
        }

        return Anomaly::withoutCompanyScope()->create([
            'company_id' => $companyId,
            'branch_id' => $f->branchId,
            'register_id' => $f->registerId,
            'kind' => $f->kind,
            'severity' => $f->severity,
            'staff_level' => $f->kind->staffLevel(),
            'subject_id' => $f->subjectId,
            'subject_name' => $f->subjectName,
            'dedupe_key' => $f->dedupeKey(),
            'group_key' => $f->groupKey(),
            'status' => AnomalyStatus::New,
            'detected_at' => $now,
            'occurrences' => 1,
            ...$this->figures($f),
        ]);
    }

    /** Refresh a row with the latest figures; returned when it became more severe (and is not dismissed). */
    private function update(Anomaly $row, AnomalyFinding $f, CarbonImmutable $now, bool $repeat): ?Anomaly
    {
        $escalated = $f->severity->rank() > $row->severity->rank();
        $row->forceFill([
            ...$this->figures($f),
            'severity' => $row->severity->max($f->severity),
            'dedupe_key' => $f->dedupeKey(),
            'occurrences' => $row->occurrences + ($repeat ? 1 : 0),
            'detected_at' => $repeat || $escalated ? $now : $row->detected_at,
            'notified_at' => $escalated ? null : $row->notified_at,
            'status' => $repeat && $row->status === AnomalyStatus::Acknowledged && $escalated ? AnomalyStatus::New : $row->status,
            'period_start' => $repeat ? $row->period_start : $f->periodStart,
        ])->save();

        return $escalated && $row->status !== AnomalyStatus::Dismissed ? $row : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function figures(AnomalyFinding $f): array
    {
        return [
            'trading_day' => $f->day,
            'period_start' => $f->periodStart,
            'period_end' => $f->periodEnd,
            'title' => mb_substr($f->title, 0, 255),
            'summary' => $f->summary,
            'facts' => $f->facts,
            'links' => $f->links,
            'score' => number_format(min($f->score, 999999.99), 2, '.', ''),
            'severity' => $f->severity,
        ];
    }
}
