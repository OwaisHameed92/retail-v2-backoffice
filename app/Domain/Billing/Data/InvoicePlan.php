<?php

namespace App\Domain\Billing\Data;

use App\Domain\Billing\Enums\BillingCycle;
use App\Domain\Billing\Support\BillingDates;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Billing\Support\Vat;
use Carbon\CarbonImmutable;

/**
 * What GenerateInvoice would create: the period, lines and totals, and any invoice already covering the period.
 * Also the JSON preview of the "Create invoice" dialog.
 */
final readonly class InvoicePlan
{
    /**
     * @param  list<array{licence_id: string, register_id: string, plan_id: string, description: string, quantity: string, unit_price: string, net: string, vat: string, gross: string, period_start: string, period_end: string, position: int}>  $lines
     * @param  array{subtotal: string, vat_total: string, total: string}  $totals
     */
    public function __construct(
        public BillingCycle $cycle,
        public CarbonImmutable $periodStart,
        public CarbonImmutable $periodEnd,
        public string $vatRate,
        public bool $prorate,
        public array $lines,
        public array $totals,
        public ?string $overlapsNumber,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'cycle' => $this->cycle->value,
            'periodStart' => $this->periodStart->format('Y-m-d'),
            'periodEnd' => $this->periodEnd->format('Y-m-d'),
            'period' => BillingDates::range($this->periodStart, $this->periodEnd),
            'vatRate' => BillingFormat::percent($this->vatRate),
            'hasVat' => Vat::enabled() && $this->vatRate !== '0.00',
            'prorate' => $this->prorate,
            'lines' => array_map(fn (array $line) => [
                'description' => $line['description'],
                'quantity' => BillingFormat::quantity($line['quantity']),
                'unitPrice' => BillingFormat::money($line['unit_price']),
                'gross' => BillingFormat::money($line['gross']),
            ], $this->lines),
            'subtotal' => BillingFormat::money($this->totals['subtotal']),
            'vatTotal' => BillingFormat::money($this->totals['vat_total']),
            'total' => BillingFormat::money($this->totals['total']),
            'overlaps' => $this->overlapsNumber,
        ];
    }
}
