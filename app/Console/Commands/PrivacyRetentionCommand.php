<?php

namespace App\Console\Commands;

use App\Domain\Privacy\Actions\ApplyRetention;
use App\Domain\Privacy\Models\PrivacySettings;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;

/**
 * Module 7.7: customer data retention. Dry run by default: lists, per business with a retention period, how many
 * customers are past it (and stores the count for the Privacy screen). `--apply` anonymises them, but only for
 * businesses that turned on "anonymise automatically"; the others are still only counted. Scheduled daily with
 * `--apply` in routes/console.php. Prints counts only, never names.
 */
class PrivacyRetentionCommand extends Command
{
    protected $signature = 'privacy:retention {--apply : Anonymise for businesses that turned on automatic anonymising} {--company=* : Only these business ids}';

    protected $description = 'List (or anonymise) customers past each business\'s data retention period';

    public function handle(ApplyRetention $retention): int
    {
        /** @var list<string> $only */
        $only = array_values(array_filter((array) $this->option('company'), 'is_string'));
        $settings = PrivacySettings::withoutCompanyScope()->whereNotNull('retention_months')
            ->when($only !== [], fn ($q) => $q->whereIn('company_id', $only))->get();
        $rows = [];

        foreach ($settings as $row) {
            $company = Company::query()->find($row->company_id);

            if ($company === null) {
                continue;
            }

            $apply = (bool) $this->option('apply') && $row->auto_anonymise;
            $outcome = $retention->handle($company, $apply);
            $rows[] = [$company->id, $row->retention_months, $outcome['due'], $apply ? 'apply' : 'dry run', $outcome['anonymised'], $outcome['skipped']];
        }

        if ($rows === []) {
            $this->info('No business has a data retention period set.');

            return self::SUCCESS;
        }

        $this->table(['Business', 'Months', 'Due', 'Mode', 'Anonymised', 'Skipped (not settled)'], $rows);

        return self::SUCCESS;
    }
}
