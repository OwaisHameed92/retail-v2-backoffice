<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\EndsWithBusinessFailures;
use App\Domain\Privacy\Actions\ApplyRetention;
use App\Domain\Privacy\Models\PrivacySettings;
use App\Domain\Shared\Support\BusinessFailures;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;

/**
 * Module 7.7: customer data retention. Dry run by default: lists, per business with a retention period, how many
 * customers are past it (and stores the count for the Privacy screen). `--apply` anonymises them, but only for
 * businesses that turned on "anonymise automatically"; the others are still only counted. Scheduled daily with
 * `--apply` in routes/console.php. Prints counts only, never names. One business's error is reported and the others
 * still run; the command then exits non-zero naming it (audit DATA-8).
 */
class PrivacyRetentionCommand extends Command
{
    use EndsWithBusinessFailures;

    public const TASK = 'privacy:retention';

    protected $signature = 'privacy:retention {--apply : Anonymise for businesses that turned on automatic anonymising} {--company=* : Only these business ids}';

    protected $description = 'List (or anonymise) customers past each business\'s data retention period';

    public function handle(ApplyRetention $retention, BusinessFailures $failures): int
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('company'), 'is_string'));
        $settings = PrivacySettings::withoutCompanyScope()->whereNotNull('retention_months')
            ->when($only !== [], fn ($q) => $q->whereIn('company_id', $only))->get();
        $rows = [];
        $failures->forget(self::TASK);

        foreach ($settings as $row) {
            $apply = (bool) $this->option('apply') && $row->auto_anonymise;
            $line = $failures->attempt(self::TASK, $row->company_id, function () use ($row, $apply, $retention) {
                $company = Company::query()->find($row->company_id);

                if ($company === null) {
                    return null;
                }

                $outcome = $retention->handle($company, $apply);

                return [$company->id, $row->retention_months, $outcome['due'], $apply ? 'apply' : 'dry run', $outcome['anonymised'], $outcome['skipped']];
            }, false);

            if ($line === false) {
                $rows[] = [$row->company_id, $row->retention_months, '-', $apply ? 'apply' : 'dry run', 'failed', '-'];
            } elseif ($line !== null) {
                $rows[] = $line;
            }
        }

        if ($rows === []) {
            $this->info('No business has a data retention period set.');

            return $this->endWith($failures->companyIds(self::TASK), 'data retention');
        }

        $this->table(['Business', 'Months', 'Due', 'Mode', 'Anonymised', 'Skipped (not settled)'], $rows);

        return $this->endWith($failures->companyIds(self::TASK), 'data retention');
    }
}
