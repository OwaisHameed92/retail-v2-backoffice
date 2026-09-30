<?php

namespace App\Domain\Reporting\Data;

/**
 * One product's sales (DASHBOARD.md §2.3 "Top products", §5.4): qty sold less returned (base units), net/gross/VAT net of refunds. Name and department are the product's current ones (fallback: the name on its newest line; "Unassigned").
 */
final readonly class ProductSales
{
    public function __construct(
        public string $productId,
        public string $name,
        public string $department,
        public string $category,
        public string $qty,
        public string $refundQty,
        public string $net,
        public string $gross,
        public string $vat,
        public string $refundNet,
        public string $discount,
        public string $cost,
    ) {}
}
