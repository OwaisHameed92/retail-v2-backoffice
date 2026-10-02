<?php

namespace App\Domain\Anomalies\Queries;

use App\Domain\Anomalies\Enums\AnomalySeverity;
use App\Domain\Anomalies\Enums\AnomalyStatus;
use App\Domain\Anomalies\Models\Anomaly;
use Carbon\CarbonImmutable;

/**
 * The "Unusual activity" section of the 07:00 digest (module 6.6): findings still new that were raised (or became more
 * severe) in the last 24 hours, most severe first, per shop. Staff-level findings sit under `staff:<shop id>` so
 * DigestSections gives them to owners and managers only. Runs as the business (DigestFindings sets CurrentCompany).
 */
final class AnomalyDigest
{
    public const STAFF = 'staff:';

    /**
     * @param  array<string, string>  $shops  active shop names by id
     * @return array<string, array{total: int, counts: array<string, int>, items: list<string>}>
     */
    public static function for(array $shops, CarbonImmutable $now): array
    {
        $rows = Anomaly::query()->where('status', AnomalyStatus::New->value)->whereIn('branch_id', array_keys($shops))
            ->where('detected_at', '>', $now->subDay())->where('detected_at', '<=', $now)
            ->orderByDesc('score')->limit(500)->get();
        $rows = $rows->sortByDesc(fn (Anomaly $a) => $a->severity->rank() * 1_000_000 + (float) $a->score)->values();
        $out = [];

        foreach ($rows as $a) {
            $key = ($a->kind->staffLevel() ? self::STAFF : '').$a->branch_id;
            $entry = $out[$key] ?? ['total' => 0, 'counts' => ['high' => 0, 'medium' => 0, 'low' => 0], 'items' => []];
            $entry['total']++;
            $entry['counts'][$a->severity->value]++;
            $entry['items'][] = ($a->severity === AnomalySeverity::High ? 'Serious: ' : '').$a->title;
            $out[$key] = $entry;
        }

        return $out;
    }
}
