<?php

namespace App\Console\Commands;

use App\Domain\Demo\Actions\EnsureDemoTenants;
use App\Domain\Demo\Actions\SeedDemoBusiness;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;

/**
 * Full demo data: a UK convenience business with every portal page filled (catalogue, stock, suppliers, customers,
 * staff, cash, offers, news, compliance, journals, till health and sales), through the real push path, then its
 * reporting tables rebuilt. Without --company it fills (and if need be makes) Khan Mini Mart and Singh Family
 * Stores. Refuses to run in production. Repeatable; --fresh removes only what the demo made.
 */
class DemoSeedCommand extends Command
{
    protected $signature = 'demo:seed
        {--company= : Business id or exact name (default: Khan Mini Mart and Singh Family Stores)}
        {--days=60 : Trading days of sales, ending today (Europe/London)}
        {--fresh : Remove the business\'s earlier demo data first}';

    protected $description = 'Fill demo businesses with complete, realistic data for every portal page (never in production)';

    public function handle(EnsureDemoTenants $tenants, SeedDemoBusiness $seed): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:seed never runs in production: it writes made-up data.');

            return self::FAILURE;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if ($days === false || $days < 1 || $days > DemoSalesCommand::MAX_DAYS) {
            $this->error('--days must be a whole number from 1 to '.DemoSalesCommand::MAX_DAYS.'.');

            return self::INVALID;
        }

        $option = $this->option('company');
        $companies = is_string($option) && $option !== ''
            ? Company::query()->where(fn ($q) => Ulid::isValid($option) ? $q->whereKey($option) : $q->where('name', $option))->get()->all()
            : $tenants->handle();

        if ($companies === []) {
            $this->error('No business matches --company='.$option.'.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $started = microtime(true);
            $this->line("<info>{$company->name}</info>: {$days} days".($this->option('fresh') ? ', replacing earlier demo data' : ''));
            $totals = $seed->handle($company, $days, (bool) $this->option('fresh'), progress: function (string $step) {
                $this->line("  {$step}…");
            });

            if ($totals['shops'] === 0) {
                $this->warn('  No active shop with an active till: nothing generated.');

                continue;
            }

            $this->line(sprintf(
                '  Done in %ss: %d shop(s), %s demo rows sent, %s sales%s. Reporting tables rebuilt.',
                number_format(microtime(true) - $started, 1), $totals['shops'], number_format($totals['rows']), number_format($totals['sales']),
                $totals['removed'] > 0 ? ', '.number_format($totals['removed']).' earlier demo rows removed' : '',
            ));
        }

        return self::SUCCESS;
    }
}
