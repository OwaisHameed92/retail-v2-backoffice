<?php

namespace App\Domain\Tenancy\Data;

/**
 * A further branch for CreateTenant (module 1.6: one per shop of a lead) with how many tills it starts with.
 */
final readonly class NewBranch
{
    public function __construct(
        public BranchDetails $details,
        public int $tills,
        /** Module 1.11: tills allowed (the key's maxRegisters); null = the tills added. */
        public ?int $tillsAllowed = null,
    ) {}
}
