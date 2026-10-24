<?php

namespace App\Domain\OnlineStore\Data;

use Carbon\CarbonImmutable;

/**
 * One collection slot of a shop (CollectionSlots). `from` / `to` are UTC; `label` is shop time ("09:00–09:30").
 * `taken` counts the slot's orders that are not cancelled; the slot is `available` while taken < capacity.
 */
final readonly class CollectionSlot
{
    public function __construct(
        public CarbonImmutable $from,
        public CarbonImmutable $to,
        public string $label,
        public int $taken,
        public int $capacity,
    ) {}

    public function available(): bool
    {
        return $this->taken < $this->capacity;
    }

    /**
     * What the public checkout gets: only whether the slot has room, never how many orders it has (audit OSA-25: that
     * is the shop's trading volume).
     *
     * @return array{from: string, to: string, label: string, available: bool}
     */
    public function toArray(): array
    {
        return [
            'from' => $this->from->toIso8601ZuluString(),
            'to' => $this->to->toIso8601ZuluString(),
            'label' => $this->label,
            'available' => $this->available(),
        ];
    }
}
