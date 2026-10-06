<?php

namespace App\Console\Commands;

use App\Domain\Reporting\Actions\GenerateDemoSales;
use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;

/**
 * Module 3.2: fills a demo business with realistic till sales for the trading dashboards, through the real push
 * path, then rebuilds its reporting tables. Refuses to run in production. Without `--company` it fills the demo
 * tenants (Khan Mini Mart, Patel News and Booze). Safe to run again: nothing is stored twice.
 */
class DemoSalesCommand extends Command
{
    /** Names of the demo tenants filled when no --company is given. */
    public const DEMO_TENANTS = ['Khan Mini Mart', 'Patel News and Booze'];

    public const MAX_DAYS = 400;

    protected $signature = 'demo:sales
        {--company= : Business id or exact name (default: the demo tenants)}
        {--days=60 : Trading days to fill, ending today (shop time zone)}
        {--fresh : Remove the business\'s earlier demo sales first}';

    protected $description = 'Generate demo till sales for the demo tenants and rebuild their reporting tables (never in production)';

    public function handle(GenerateDemoSales $generate): int
    {
        if ($this->laravel->environment('production')) {
            $this->error('demo:sales never runs in production: it writes made-up sales.');

            return self::FAILURE;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);

        if ($days === false || $days < 1 || $days > self::MAX_DAYS) {
            $this->error('--days must be a whole number from 1 to '.self::MAX_DAYS.'.');

            return self::INVALID;
        }

        $companies = $this->companies();

        if ($companies->isEmpty()) {
            $this->error($this->option('company') !== null
                ? 'No business matches --company='.$this->option('company').'.'
                : 'None of the demo tenants ('.implode(', ', self::DEMO_TENANTS).') exists. Pass --company=<id or name>.');

            return self::FAILURE;
        }

        foreach ($companies as $company) {
            $this->line("<info>{$company->name}</info>: {$days} days".($this->option('fresh') ? ', replacing earlier demo sales' : ''));
            $totals = $generate->handle($company, $days, (bool) $this->option('fresh'), progress: function (string $shop, string $day, array $counts) {
                if ($this->output->isVerbose()) {
                    $this->line("  {$shop} {$day}: {$counts['sales']} sales, {$counts['refunds']} refunds, {$counts['voids']} voids");
                }
            });

            if ($totals['shops'] === 0) {
                $this->warn('  No active shop with an active till: nothing generated.');

                continue;
            }

            $this->line(sprintf(
                '  %d %s: %s sales, %s refunds, %s voided baskets%s. Reporting tables rebuilt.',
                $totals['shops'], Str::plural('shop', $totals['shops']), number_format($totals['sales']), number_format($totals['refunds']), number_format($totals['voids']),
                $totals['removed'] > 0 ? ', '.number_format($totals['removed']).' earlier demo sales removed' : '',
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Company>
     */
    private function companies(): Collection
    {
        $option = $this->option('company');

        if (is_string($option) && $option !== '') {
            return Company::query()->where(fn ($q) => Ulid::isValid($option) ? $q->whereKey($option) : $q->where('name', $option))->get();
        }

        return Company::query()->whereIn('name', self::DEMO_TENANTS)->orderBy('name')->get();
    }
}
