<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Actions\FollowPlanFeatures;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;

/**
 * Fix 2026-10-07: branches created by the admin wizard, trial approval or "Add branch" were pinned to a copy of the
 * plan's features. This sets the copies that equal the plan back to "follow the plan" and lists the branches whose
 * features really differ (changed only for one business with `--company=… --reset`). Safe to run again.
 */
class LicencesFeaturesFollowPlanCommand extends Command
{
    protected $signature = 'licences:features-follow-plan
        {--company= : Only this business (id)}
        {--dry-run : Show what would change, change nothing}
        {--reset : With --company: also reset branches whose features differ to the plan\'s}';

    protected $description = 'Let branches whose licence features equal their plan\'s follow the plan again; list those that differ';

    public function handle(FollowPlanFeatures $follow): int
    {
        $companyId = $this->option('company');
        $company = null;

        if (is_string($companyId) && $companyId !== '') {
            $company = Company::query()->find($companyId);

            if ($company === null) {
                $this->error("No business with id {$companyId}.");

                return self::FAILURE;
            }
        }

        if ($this->option('reset') && $company === null) {
            $this->error('--reset needs --company: features that differ from the plan are reset one business at a time.');

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $rows = $follow->handle($company, $dryRun, (bool) $this->option('reset'));

        if ($rows === []) {
            $this->info('Every branch already follows its plan\'s features. Nothing to change.');

            return self::SUCCESS;
        }

        $this->table(['Business', 'Branch', 'Plan', 'Branch features', 'Plan features', 'Result'], array_map(fn (array $row) => [
            $row['company'], $row['branch'], $row['plan'] ?? 'no plan', implode(', ', $row['features']) ?: 'none',
            implode(', ', $row['planFeatures']) ?: 'none', $this->outcome($row['outcome'], $row['error'], $dryRun),
        ], $rows));

        $counts = array_count_values(array_column($rows, 'outcome'));
        $failed = $counts[FollowPlanFeatures::FAILED] ?? 0;
        $reset = $counts[FollowPlanFeatures::RESET] ?? 0;

        $this->info(sprintf(
            '%s %d to follow the plan; %d differ%s.',
            $dryRun ? 'Would set' : 'Set',
            $counts[FollowPlanFeatures::FOLLOWS] ?? 0,
            ($counts[FollowPlanFeatures::DIFFERS] ?? 0) + $reset,
            $reset > 0 ? ($dryRun ? " ({$reset} would be reset to the plan)" : " ({$reset} reset to the plan)") : '',
        ));

        if ($failed > 0) {
            $this->warn("{$failed} could not be saved; see the table.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function outcome(string $outcome, ?string $error, bool $dryRun): string
    {
        return match ($outcome) {
            FollowPlanFeatures::FOLLOWS => $dryRun ? 'would follow the plan' : 'now follows the plan',
            FollowPlanFeatures::RESET => $dryRun ? 'would reset to the plan' : 'reset to the plan',
            FollowPlanFeatures::FAILED => 'failed: '.$error,
            default => 'differs (kept)',
        };
    }
}
