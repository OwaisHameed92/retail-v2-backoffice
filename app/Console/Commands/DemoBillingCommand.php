<?php

namespace App\Console\Commands;

use App\Domain\Billing\Data\BillingStatusData;
use App\Domain\Billing\Support\BillingStatus;
use App\Domain\Demo\Billing\BuildDemoBillingBusiness;
use App\Domain\Demo\Billing\DemoBillingScenario;
use App\Domain\Tenancy\Actions\PurgeCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;

/**
 * The billing showcase: demo businesses (`is_demo`), each in one billing case, made through the real actions with
 * the clock set back, so the Billing tab, the portal and the overview show exactly what a real customer would.
 * Default: "DEMO – Setup + monthly" only; --scenario=<case>|all for the others. Demo businesses are never sent to
 * GoCardless and never emailed (safe with LIVE GoCardless keys). --fresh removes the demo businesses first (only
 * those: PurgeCompany on `is_demo` companies). Production needs --force.
 */
class DemoBillingCommand extends Command
{
    protected $signature = 'demo:billing
        {--scenario= : One of setup-monthly (default), setup-only-paid, setup-only-unpaid, waiting-for-dd, payment-failed, instalments, or all}
        {--fresh : Remove every demo business (and only those) first}
        {--force : Allow it in production}';

    protected $description = 'Make demo businesses that show each billing case (never sent to GoCardless, never emailed)';

    public function handle(BuildDemoBillingBusiness $build, PurgeCompany $purge): int
    {
        if ($this->laravel->environment('production') && ! $this->option('force')) {
            $this->error('This is production: add --force to make demo businesses here.');
            $this->line('It is safe: demo businesses are never sent to GoCardless and never get an email (logged as "Not sent (demo)").');

            return self::FAILURE;
        }

        $option = (string) ($this->option('scenario') ?? '');
        $scenarios = match (true) {
            $option === '' => [DemoBillingScenario::DEFAULT],
            $option === 'all' => DemoBillingScenario::cases(),
            default => array_filter([DemoBillingScenario::tryFrom($option)]),
        };

        if ($scenarios === []) {
            $this->error("Unknown --scenario={$option}. Use one of: ".implode(', ', DemoBillingScenario::values()).', all.');

            return self::INVALID;
        }

        $this->line('<comment>Demo businesses are never sent to GoCardless (even with live keys) and never get an email.</comment>');

        if ($this->option('fresh')) {
            $removed = 0;

            foreach (Company::withTrashed()->where('is_demo', true)->get() as $demo) {
                $purge->handle($demo, force: true);
                $removed++;
            }

            $this->line("Removed {$removed} demo ".($removed === 1 ? 'business' : 'businesses').' (real businesses untouched).');
        }

        $rows = [];

        foreach ($scenarios as $scenario) {
            $existing = Company::query()->where('is_demo', true)->where('name', $scenario->businessName())->first();
            $company = $existing ?? $build->handle($scenario);
            $status = BillingStatusData::for(BillingStatus::for($company));
            $rows[] = [$company->name, $status['headline'], $status['next']['text'] ?? '—', $existing !== null ? 'already there' : 'made'];
        }

        $this->table(['Business', 'Billing status', 'What happens next', ''], $rows);
        $this->line('See them in Admin → Billing (filter by state) or on each business\'s Billing tab. Remove them with --fresh.');

        return self::SUCCESS;
    }
}
