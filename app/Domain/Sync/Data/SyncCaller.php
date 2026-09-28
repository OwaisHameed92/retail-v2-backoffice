<?php

namespace App\Domain\Sync\Data;

use App\Domain\Sync\Support\IdTranslator;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;

/**
 * A `sync/*` request after its Bearer key was checked (AuthenticateSyncRequest): our company and branch, the key,
 * the sending register as ours (null when not mapped or not sent), the till's own ids from the headers (to echo
 * back) and the company's IdTranslator for the envelopes.
 */
final readonly class SyncCaller
{
    public function __construct(
        public Company $company,
        public Branch $branch,
        public string $syncKeyId,
        public ?string $registerId,
        public string $tillCompanyId,
        public string $tillBranchId,
        public IdTranslator $ids,
    ) {}
}
