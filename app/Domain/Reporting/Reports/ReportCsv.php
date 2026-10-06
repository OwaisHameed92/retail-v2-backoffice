<?php

namespace App\Domain\Reporting\Reports;

use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;

/**
 * A report as CSV (module 4.8): a heading block (report, business, shop, till, dates, compare), the headline figures,
 * then every table with its totals row. Numbers are plain decimals (no £ or thousands separators) so a spreadsheet
 * reads them; times in the shops' time zone. Text cells that a spreadsheet would run as a formula are quoted with '.
 */
final class ReportCsv
{
    /**
     * @param  array<string, string>  $heading  label => value
     * @return list<list<string>>
     */
    public static function lines(ReportResult $result, array $heading): array
    {
        $lines = [];

        foreach ($heading as $label => $value) {
            $lines[] = [$label, self::text($value)];
        }

        foreach ($result->notes as $note) {
            $lines[] = ['Note', self::text($note)];
        }

        if ($result->summary !== []) {
            $lines[] = [];
            $lines[] = ['Figure', 'Value', 'Compare period', 'Change %'];

            foreach ($result->summary as $f) {
                $lines[] = [(string) $f['label'], self::cell($f['value'], (string) $f['type']), self::cell($f['previous'] ?? null, (string) $f['type']), (string) ($f['change'] ?? '')];
            }
        }

        foreach ($result->tables as $table) {
            $lines[] = [];
            $lines[] = [self::text($table->title)];
            $lines[] = array_map(fn (array $c) => $c['label'], $table->columns);

            foreach ($table->rows as $row) {
                $lines[] = self::row($table, $row);
            }

            if ($table->totals !== null) {
                $lines[] = self::row($table, $table->totals);
            }
        }

        return $lines;
    }

    /**
     * @param  list<list<string>>  $lines
     */
    public static function write(array $lines): void
    {
        $out = fopen('php://output', 'w');

        if ($out === false) {
            return;
        }

        fwrite($out, "\xEF\xBB\xBF"); // UTF-8 BOM: Excel opens "£" and "–" correctly.

        foreach ($lines as $line) {
            fputcsv($out, $line, ',', '"', '');
        }

        fclose($out);
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function row(ReportTable $table, array $row): array
    {
        return array_map(fn (array $c) => self::cell($row[$c['key']] ?? null, $c['type']), $table->columns);
    }

    private static function cell(mixed $value, string $type): string
    {
        if ($value === null) {
            return '';
        }

        return match ($type) {
            'datetime' => CarbonImmutable::parse((string) $value)->setTimezone(Country::zone())->format('Y-m-d H:i'),
            'flag' => $value === true ? 'Yes' : '',
            'status' => match ((string) $value) {
                'out' => 'Out of stock',
                'low' => 'Low',
                default => 'OK',
            },
            'text' => self::text((string) $value),
            default => (string) $value,
        };
    }

    private static function text(string $value): string
    {
        return $value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$value : $value;
    }
}
