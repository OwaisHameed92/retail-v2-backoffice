<?php

namespace App\Domain\Leads\Data;

/**
 * What "Approve 7-day trial" creates, as confirmed in the approval dialog: the shops (branches) with their tills,
 * and the plan the tills are licensed on (null = the portal default plan).
 */
final readonly class TrialSetup
{
    /**
     * @param  list<TrialShop>  $shops
     */
    public function __construct(
        public array $shops,
        public ?string $planId = null,
    ) {}

    public function totalTills(): int
    {
        return array_sum(array_map(fn (TrialShop $shop) => $shop->tills, $this->shops));
    }

    /**
     * @return array{shops: list<array{name: string, code: string, tills: int, nation: string}>, planId: string|null}
     */
    public function toArray(): array
    {
        return [
            'shops' => array_map(fn (TrialShop $shop) => $shop->toArray(), $this->shops),
            'planId' => $this->planId,
        ];
    }
}
