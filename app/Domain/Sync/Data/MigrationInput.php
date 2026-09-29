<?php

namespace App\Domain\Sync\Data;

use App\Domain\Licensing\Api\TillRequest;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * A validated `cloud/migrate` body (migrate-request.schema.json, contract v1.4.1 §17.8). `till` carries the PC and
 * `existingIds` = the till's own company, branch and main-till register ids. Never logged: `activationCode` is the
 * credential.
 */
final readonly class MigrationInput
{
    /**
     * @param  list<array{registerId: string, isMain: bool, isActive: bool}>  $registers
     * @param  array<string, int>  $rowCounts
     */
    public function __construct(
        #[SensitiveParameter] public string $activationCode,
        public TillRequest $till,
        public ?string $localLicenceToken,
        public string $companyName,
        public string $branchName,
        public array $registers,
        public int $totalRows,
        public array $rowCounts,
        public ?CarbonImmutable $firstSaleAt,
        public ?CarbonImmutable $lastSaleAt,
        public int $snapshotChangeLogSeq,
    ) {}

    public function tillCompanyId(): string
    {
        return (string) ($this->till->existingIds['companyId'] ?? '');
    }

    public function tillBranchId(): string
    {
        return (string) ($this->till->existingIds['branchId'] ?? '');
    }

    public function tillRegisterId(): ?string
    {
        return $this->till->existingIds['registerId'] ?? null;
    }
}
