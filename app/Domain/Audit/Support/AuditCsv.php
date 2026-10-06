<?php

namespace App\Domain\Audit\Support;

use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Models\AuditLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The filtered audit log as CSV, streamed newest first in chunks (no row limit, flat memory). Times are in the shops' time zone.
 * Cells that a spreadsheet would run as a formula are prefixed with an apostrophe.
 */
final class AuditCsv
{
    private const CHUNK = 500;

    /**
     * @param  Builder<AuditLog>  $query
     */
    public static function download(Builder $query, bool $tenantView, string $filename): StreamedResponse
    {
        $presenter = new AuditPresenter($tenantView);

        return response()->streamDownload(function () use ($query, $presenter, $tenantView) {
            $out = fopen('php://output', 'w');

            if ($out === false) {
                return;
            }

            fputcsv($out, self::header($tenantView), escape: '');

            $query->clone()->chunkByIdDesc(self::CHUNK, function ($chunk) use ($out, $presenter, $tenantView) {
                foreach ($presenter->rows($chunk) as $row) {
                    fputcsv($out, array_map(self::safe(...), self::line($row, $tenantView)), escape: '');
                }
            });

            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Cache-Control' => 'no-store',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function header(bool $tenantView): array
    {
        return array_values(array_filter([
            'Time (UK)', 'Who', 'Who (detail)', $tenantView ? null : 'Business', 'Action', 'Action code',
            'Record type', 'Record id', 'Changes', 'Details', 'IP address',
        ]));
    }

    /**
     * @param  array<string, mixed>  $row
     * @return list<string>
     */
    private static function line(array $row, bool $tenantView): array
    {
        /** @var array{name: string, detail: string|null} $actor */
        $actor = $row['actor'];
        /** @var list<array{label: string, before: string|null, after: string|null}> $changes */
        $changes = $row['changes'];
        /** @var list<array{label: string, value: string|null}> $meta */
        $meta = $row['meta'];
        $subject = is_array($row['subject']) ? $row['subject'] : null;
        $company = is_array($row['company']) ? $row['company'] : null;

        $cells = [
            is_string($row['at']) ? Carbon::parse($row['at'])->setTimezone(Country::zone())->format('Y-m-d H:i:s') : '',
            $actor['name'],
            (string) ($actor['detail'] ?? ''),
        ];

        if (! $tenantView) {
            $cells[] = (string) ($company['name'] ?? '');
        }

        return [
            ...$cells,
            (string) $row['actionLabel'],
            (string) $row['action'],
            (string) ($subject['label'] ?? ''),
            (string) ($subject['id'] ?? ''),
            implode('; ', array_map(fn (array $c) => "{$c['label']}: ".($c['before'] ?? '—').' → '.($c['after'] ?? '—'), $changes)),
            implode('; ', array_map(fn (array $m) => "{$m['label']}: ".($m['value'] ?? '—'), $meta)),
            (string) ($row['ip'] ?? ''),
        ];
    }

    private static function safe(string $cell): string
    {
        return $cell !== '' && in_array($cell[0], ['=', '+', '-', '@', "\t", "\r"], true) ? "'".$cell : $cell;
    }
}
