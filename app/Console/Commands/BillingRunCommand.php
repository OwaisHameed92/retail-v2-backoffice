<?php

namespace App\Console\Commands;

use App\Domain\Billing\Actions\RunBilling;
use Illuminate\Console\Command;

/**
 * Daily cash billing run (module 1.8): create due invoices, mark overdue invoices, suspend companies unpaid past
 * the grace, send trial emails. Idempotent. Scheduled in routes/console.php. Prints counts only.
 */
class BillingRunCommand extends Command
{
    protected $signature = 'billing:run';

    protected $description = 'Create due invoices, mark overdue ones, suspend unpaid accounts and send trial emails';

    public function handle(RunBilling $run): int
    {
        $result = $run->handle();

        $this->info('Billing run finished.');
        $this->line("  Invoices created: {$result['invoicesCreated']}");
        $this->line("  Invoices now overdue: {$result['invoicesOverdue']}");
        $this->line("  Businesses suspended: {$result['companiesSuspended']}");
        $this->line("  Trial reminders sent: {$result['trialReminders']}");
        $this->line("  Trial ended emails sent: {$result['trialEnded']}");
        $this->line("  Suspended without a Direct Debit: {$result['noMandateSuspended']}");
        $this->line("  Overdue after a lost Direct Debit: {$result['mandateOverdue']}");
        $this->line("  Direct Debit reminders sent: {$result['directDebitReminders']}");

        return self::SUCCESS;
    }
}
