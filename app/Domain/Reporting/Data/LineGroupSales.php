<?php

namespace App\Domain\Reporting\Data;

/**
 * Sales of one department or category (DASHBOARD.md §2.3), grouped by the product's current one; `id` null = "Unassigned".
 */
final readonly class LineGroupSales
{
    public function __construct(
        public ?string $id,
        public string $name,
        public string $qty,
        public string $net,
        public string $gross,
    ) {}
}
