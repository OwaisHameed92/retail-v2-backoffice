<?php

namespace App\Domain\Reporting\Demo;

/**
 * One shop that gets demo sales: its business, code, active tills (main till first) and cashiers. `seed` makes
 * every id and every random choice repeatable for this shop.
 */
final readonly class DemoShop
{
    public string $seed;

    /** @var list<string> */
    public array $cashiers;

    /**
     * @param  list<array{id: string, code: string}>  $registers  main till first
     */
    public function __construct(
        public string $companyId,
        public string $branchId,
        public string $branchCode,
        public array $registers,
    ) {
        $this->seed = "demo|{$companyId}|{$branchId}";
        $this->cashiers = [$this->id('cashier|1'), $this->id('cashier|2'), $this->id('cashier|3')];
    }

    /** A fixed id for something of this business (product, VAT rate, payment type) or shop (cashier, shift). */
    public function id(string $what): string
    {
        $businessWide = str_starts_with($what, 'product|') || str_starts_with($what, 'vat|') || str_starts_with($what, 'tender|') || str_starts_with($what, 'promotion|');

        return DemoIds::fixed(($businessWide ? "demo|{$this->companyId}" : $this->seed).'|'.$what);
    }

    /** Daily transactions of this shop on an ordinary Thursday: 180–340, fixed per shop. */
    public function baseTransactions(): int
    {
        return 180 + abs(crc32($this->seed)) % 161;
    }
}
