<?php

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\CompanyRole;
use App\Domain\Tenancy\Exceptions\MissingCurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Closure;
use Illuminate\Container\Attributes\Scoped;

/**
 * The active tenant for the current request, job or command.
 *
 * Scoped: one instance per request / queued job (the container flushes scoped instances between them).
 * - Web: set by the EnsureCompanyMember middleware from the user's active memberships.
 * - Queue jobs and console: nothing is set; call set() or runAs() explicitly before touching tenant data.
 */
#[Scoped]
class CurrentCompany
{
    private ?Company $company = null;

    private ?CompanyRole $role = null;

    public function set(Company $company, ?CompanyRole $role = null): void
    {
        $this->company = $company;
        $this->role = $role;
    }

    public function forget(): void
    {
        $this->company = null;
        $this->role = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function get(): ?Company
    {
        return $this->company;
    }

    public function id(): ?string
    {
        return $this->company?->getKey();
    }

    /**
     * The current company, or throw (fail closed).
     */
    public function require(): Company
    {
        return $this->company ?? throw new MissingCurrentCompany('No current company is set.');
    }

    /**
     * The signed-in user's role in the current company (null for system contexts such as jobs).
     */
    public function role(): ?CompanyRole
    {
        return $this->role;
    }

    public function can(Ability|string $ability): bool
    {
        return $this->company !== null && $this->role?->can($ability) === true;
    }

    /**
     * The abilities the current user has in the current company.
     *
     * @return list<string>
     */
    public function abilities(): array
    {
        if ($this->company === null || $this->role === null) {
            return [];
        }

        return array_map(fn (Ability $ability) => $ability->value, $this->role->abilities());
    }

    /**
     * Run a callback as the given company, then restore whatever was current before.
     * Use this in queue jobs, console commands and sync code.
     *
     * @template T
     *
     * @param  Closure(Company): T  $callback
     * @return T
     */
    public function runAs(Company $company, Closure $callback, ?CompanyRole $role = null): mixed
    {
        $previousCompany = $this->company;
        $previousRole = $this->role;

        $this->set($company, $role);

        try {
            return $callback($company);
        } finally {
            $this->company = $previousCompany;
            $this->role = $previousRole;
        }
    }
}
