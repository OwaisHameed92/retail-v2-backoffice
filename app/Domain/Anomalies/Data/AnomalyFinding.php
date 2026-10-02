<?php

namespace App\Domain\Anomalies\Data;

use App\Domain\Anomalies\Enums\AnomalyKind;
use App\Domain\Anomalies\Enums\AnomalySeverity;
use Carbon\CarbonImmutable;

/**
 * What one detector found (module 6.6), before it is stored: deterministic text and figures only.
 *
 * - `facts`: the measures, each with this period's value and the usual one (own baseline and / or peers), as display
 *   strings ("£45.00", "9", "7.5 per 100 sales");
 * - `links`: portal paths of the drill-downs (sales list filtered, shift, staff, exceptions…);
 * - `subjectId`: the till user (staff-level kinds) or till (till-level kinds), part of the dedupe key.
 */
final readonly class AnomalyFinding
{
    /**
     * @param  list<array{label: string, value: string, usual?: string|null, peers?: string|null}>  $facts
     * @param  list<array{label: string, href: string}>  $links
     */
    public function __construct(
        public AnomalyKind $kind,
        public AnomalySeverity $severity,
        public string $branchId,
        public string $day,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $title,
        public string $summary,
        public array $facts,
        public array $links,
        public float $score,
        public ?string $subjectId = null,
        public ?string $subjectName = null,
        public ?string $registerId = null,
    ) {}

    /** The exact finding: one per kind, shop, subject and trading day. */
    public function dedupeKey(): string
    {
        return $this->groupKey().'|'.$this->day;
    }

    /** The same thing seen again: kind, shop and subject. */
    public function groupKey(): string
    {
        return $this->kind->value.'|'.$this->branchId.'|'.($this->subjectId ?? $this->registerId ?? '-');
    }
}
