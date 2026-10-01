<?php

namespace App\Domain\Accounts\Export;

use App\Domain\Accounts\Data\AccountsFilters;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use RuntimeException;

/**
 * Exports the period's summary journals as one package's CSV (gap #8): JournalSummary with the business's mapping,
 * written by the package's JournalFormat. Audited (`accounts.exported`: package, dates, shop, journal count).
 *
 *     ['csv' => $csv, 'filename' => $name] = app(ExportJournals::class)->handle($company, $filters, ExportTarget::Xero, 'daily');
 */
final class ExportJournals
{
    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @return array{csv: string, filename: string, journals: int}
     */
    public function handle(Company $company, AccountsFilters $filters, ExportTarget $target, string $grouping): array
    {
        return $this->tenancy->runAs($company, function (Company $company) use ($filters, $target, $grouping): array {
            $summary = JournalSummary::build($filters, ExportMappings::for($target), $grouping, (string) $company->name);
            $csv = self::csv($target->format(), $summary['journals']);

            $this->audit->handle('accounts.exported', null, null, null, [
                'package' => $target->label(), 'from' => $filters->from, 'to' => $filters->to, 'shop' => $filters->shop ?? 'every shop',
                'grouping' => $grouping, 'journals' => $summary['totals']['journals'], 'unmapped' => count($summary['unmapped']),
            ], companyId: $company->id);

            return [
                'csv' => $csv,
                'filename' => "journals-{$target->value}-{$filters->from}-to-{$filters->to}.csv",
                'journals' => $summary['totals']['journals'],
            ];
        });
    }

    /**
     * @param  list<array<string, mixed>>  $journals
     */
    public static function csv(JournalFormat $format, array $journals): string
    {
        $out = fopen('php://temp', 'w+');

        if ($out === false) {
            throw new RuntimeException('Could not write the CSV.');
        }

        fputcsv($out, $format->header(), escape: '');

        foreach ($journals as $journal) {
            foreach ($format->rows($journal) as $row) {
                fputcsv($out, $row, escape: '');
            }
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return $csv;
    }
}
