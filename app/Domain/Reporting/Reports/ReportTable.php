<?php

namespace App\Domain\Reporting\Reports;

/**
 * One table of a report, the same shape on screen, in print and in the CSV. Column types: `text`, `money` (pounds,
 * 2 dp), `signedMoney` (red when negative: variances), `qty` (up to 4 dp), `count`, `percent` (1 dp), `date`
 * ("Y-m-d"), `datetime` (ISO UTC, shown in the shops' time zone), `status` (ok / low / out), `flag` (true = alert). Values
 * are strings / ints / bools, never floats; null = "—".
 */
final readonly class ReportTable
{
    /**
     * @param  list<array{key: string, label: string, type: string}>  $columns
     * @param  list<array<string, string|int|bool|null>>  $rows
     * @param  array<string, string|int|null>|null  $totals
     * @param  array{page: int, lastPage: int, total: int, perPage: int}|null  $pagination
     */
    public function __construct(
        public string $key,
        public string $title,
        public array $columns,
        public array $rows,
        public ?array $totals = null,
        public ?string $description = null,
        public string $empty = 'Nothing in this range.',
        public ?array $pagination = null,
    ) {}

    /**
     * @return array{key: string, label: string, type: string}
     */
    public static function col(string $key, string $label, string $type = 'text'): array
    {
        return ['key' => $key, 'label' => $label, 'type' => $type];
    }

    /**
     * @return array{page: int, lastPage: int, total: int, perPage: int}
     */
    public static function page(int $total, ReportOptions $options): array
    {
        $per = ReportOptions::PAGE_SIZE;

        return ['page' => max(1, min($options->page, (int) max(1, ceil($total / $per)))), 'lastPage' => (int) max(1, ceil($total / $per)), 'total' => $total, 'perPage' => $per];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return get_object_vars($this);
    }
}
