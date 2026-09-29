<?php

namespace App\Domain\TillData\Data;

use App\Domain\Shared\Support\Money;
use App\Domain\TillData\Enums\PurchaseOrderStatus;

/**
 * A head-office purchase order drafted on the portal for one shop (contract v1.4 §10.6). `status` is `draft` (a
 * heads-up, not receivable), `sent` (placed with the supplier: the shop can receive at once) or `cancelled`.
 */
final readonly class HeadOfficeOrderData
{
    /**
     * @param  list<HeadOfficeOrderLine>  $lines
     */
    public function __construct(
        public string $supplierId,
        public PurchaseOrderStatus $status,
        public array $lines,
        public ?string $expectedDate = null,
        public ?string $notes = null,
        public ?string $cancelReason = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  supplierId, status, expectedDate (Y-m-d), notes, cancelReason, lines[]
     */
    public static function fromArray(array $input): self
    {
        return new self(
            (string) ($input['supplierId'] ?? ''),
            PurchaseOrderStatus::tryFrom((string) ($input['status'] ?? '')) ?? PurchaseOrderStatus::Draft,
            array_values(array_map(fn (array $line) => HeadOfficeOrderLine::fromArray($line), (array) ($input['lines'] ?? []))),
            isset($input['expectedDate']) ? (string) $input['expectedDate'] : null,
            isset($input['notes']) ? (string) $input['notes'] : null,
            isset($input['cancelReason']) ? (string) $input['cancelReason'] : null,
        );
    }

    /** @return array{net: string, vat: string, gross: string} */
    public function totals(): array
    {
        $net = Money::sum(array_map(fn (HeadOfficeOrderLine $line) => $line->net(), $this->lines));
        $vat = Money::sum(array_map(fn (HeadOfficeOrderLine $line) => $line->vat(), $this->lines));

        return ['net' => $net, 'vat' => $vat, 'gross' => Money::add($net, $vat)];
    }
}
