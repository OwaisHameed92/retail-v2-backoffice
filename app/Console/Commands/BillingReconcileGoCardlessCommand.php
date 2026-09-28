<?php

namespace App\Console\Commands;

use App\Domain\Billing\GoCardless\Actions\ReconcileGoCardless;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Daily GoCardless reconcile (module 1.12): replays failed webhook events and fixes drift between GoCardless and
 * our records (mandates, subscriptions, the subscription amount, payments). Does nothing without a token.
 */
class BillingReconcileGoCardlessCommand extends Command
{
    protected $signature = 'billing:reconcile-gocardless {--dry-run : Show what would change without changing anything}';

    protected $description = 'Compare GoCardless mandates, subscriptions and payments with our records and fix drift';

    public function handle(ReconcileGoCardless $reconcile): int
    {
        $dry = (bool) $this->option('dry-run');
        $report = $reconcile->handle(CarbonImmutable::now(), $dry);

        foreach ($report['fixes'] as $fix) {
            $this->line('  '.($dry ? '[dry run] ' : '').$fix);
        }

        foreach ($report['errors'] as $error) {
            $this->warn('  '.$error);
        }

        $this->info(sprintf('%d checked, %d %s, %d errors.', $report['checked'], count($report['fixes']), $dry ? 'would change' : 'fixed', count($report['errors'])));

        return $report['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
