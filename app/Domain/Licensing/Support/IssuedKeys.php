<?php

namespace App\Domain\Licensing\Support;

use App\Domain\Licensing\Data\IssuedLicence;
use Illuminate\Container\Attributes\Scoped;

/**
 * Plain keys issued during the current request or job, in memory only. Licences issued automatically (a till
 * added through AddRegister) land here so whoever started the change can hand the keys on exactly once: the
 * welcome email (CreateTenant) or the admin's "Licence key created" dialog (add till / add branch).
 *
 * Scoped: flushed between requests and queued jobs, never persisted, never serialised.
 */
#[Scoped]
final class IssuedKeys
{
    /** @var array<string, IssuedLicence> keyed by licence id */
    private array $issued = [];

    public function add(IssuedLicence $issued): void
    {
        $this->issued[$issued->licence->id] = $issued;
    }

    /**
     * Take (and forget) every key issued for this company, in the order they were issued.
     *
     * @return list<IssuedLicence>
     */
    public function pullForCompany(string $companyId): array
    {
        $taken = [];

        foreach ($this->issued as $id => $issued) {
            if ($issued->licence->company_id === $companyId) {
                $taken[] = $issued;
                unset($this->issued[$id]);
            }
        }

        return $taken;
    }

    /** Drop everything (e.g. after a rolled-back transaction). */
    public function forget(): void
    {
        $this->issued = [];
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['issued' => count($this->issued)];
    }

    /**
     * @return array<string, mixed>
     */
    public function __serialize(): array
    {
        return [];
    }
}
