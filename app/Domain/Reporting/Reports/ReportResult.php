<?php

namespace App\Domain\Reporting\Reports;

/**
 * What a report builder returns: headline figures (with the change against the compare window where the report
 * compares), an optional chart, its tables, and notes to show above them. `available` false = the data does not
 * exist yet (stock before the tills send it) — the page explains instead of showing zeros.
 */
final readonly class ReportResult
{
    /**
     * @param  list<array<string, mixed>>  $summary  from {@see Figures}
     * @param  list<ReportTable>  $tables
     * @param  array<string, mixed>|null  $chart
     * @param  list<string>  $notes
     */
    public function __construct(
        public array $summary,
        public array $tables,
        public ?array $chart = null,
        public array $notes = [],
        public bool $available = true,
    ) {}

    public function table(string $key): ?ReportTable
    {
        foreach ($this->tables as $table) {
            if ($table->key === $key) {
                return $table;
            }
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'summary' => $this->summary,
            'tables' => array_map(fn (ReportTable $t) => $t->toArray(), $this->tables),
            'chart' => $this->chart,
            'notes' => $this->notes,
            'available' => $this->available,
        ];
    }
}
