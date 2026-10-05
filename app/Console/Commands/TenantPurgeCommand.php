<?php

namespace App\Console\Commands;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Actions\PurgeCompany;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

/**
 * Removes one business and everything that belongs to it, for good (PurgeCompany). Shows what will go (rows per
 * table) and asks first. A business that has traded (an activated licence or any sale) needs --force.
 */
class TenantPurgeCommand extends Command
{
    protected $signature = 'tenant:purge
        {company : Business id or exact name}
        {--force : Also remove a business that has traded (activated licence or sales)}';

    protected $description = 'Delete one business and every row that belongs to it (asks first)';

    public function handle(PurgeCompany $purge): int
    {
        $key = trim((string) $this->argument('company'));
        $matches = Company::withTrashed()->where(fn ($q) => Ulid::isValid($key) ? $q->whereKey($key) : $q->where('name', $key))->get();

        if ($matches->isEmpty()) {
            $this->error("No business has the id or exact name \"{$key}\".");

            return self::FAILURE;
        }

        if ($matches->count() > 1) {
            $this->error("{$matches->count()} businesses are called \"{$key}\". Use the id instead:");
            $matches->each(fn (Company $company) => $this->line("  {$company->id}  {$company->name}"));

            return self::FAILURE;
        }

        /** @var Company $company */
        $company = $matches->first();
        $this->line("<info>{$company->name}</info> ({$company->id})".($company->is_demo ? ' · demo business' : ''));
        $this->table(['Table', 'Rows'], collect($purge->plan($company))->map(fn (int $count, string $table) => [$table, number_format($count)])->values()->all());

        $blockers = $purge->blockers($company);

        if ($blockers !== [] && ! $this->option('force')) {
            $this->error("{$company->name} has traded (".implode(', ', $blockers).'). Nothing removed. Add --force to remove it anyway.');

            return self::FAILURE;
        }

        if (! $this->confirm("Delete {$company->name} and everything above for good? This cannot be undone.")) {
            $this->line('Nothing removed.');

            return self::SUCCESS;
        }

        try {
            $removed = $purge->handle($company, force: (bool) $this->option('force'));
        } catch (ValidationException $exception) {
            $this->error(collect($exception->errors())->flatten()->first() ?? $exception->getMessage());

            return self::FAILURE;
        }

        $this->info("{$company->name} removed: ".number_format(array_sum($removed)).' rows.');

        return self::SUCCESS;
    }
}
