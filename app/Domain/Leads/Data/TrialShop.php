<?php

namespace App\Domain\Leads\Data;

use App\Domain\Tenancy\Enums\Nation;

/**
 * One shop of an approved trial: becomes a branch with this many tills (the first till is the main till).
 */
final readonly class TrialShop
{
    public function __construct(
        public string $name,
        public string $code,
        public int $tills,
        public Nation $nation = Nation::England,
        /** Module 1.11: tills allowed (the keys' maxRegisters); null = the tills. */
        public ?int $tillsAllowed = null,
    ) {}

    /**
     * @return array{name: string, code: string, tills: int, nation: string, tillsAllowed: int}
     */
    public function toArray(): array
    {
        return ['name' => $this->name, 'code' => $this->code, 'tills' => $this->tills, 'nation' => $this->nation->value, 'tillsAllowed' => max($this->tills, $this->tillsAllowed ?? 0)];
    }
}
