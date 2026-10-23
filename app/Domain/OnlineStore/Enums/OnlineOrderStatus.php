<?php

namespace App\Domain\OnlineStore\Enums;

/**
 * Where an online order is (docs/online-store.md "Orders"): new → picking → ready → collected, or cancelled (with a
 * reason) from any open stage. Collected and cancelled are final. Moved by ChangeOnlineOrderStatus only.
 */
enum OnlineOrderStatus: string
{
    case New = 'new';
    case Picking = 'picking';
    case Ready = 'ready';
    case Collected = 'collected';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::New => 'New',
            self::Picking => 'Picking',
            self::Ready => 'Ready to collect',
            self::Collected => 'Collected',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * The stages this one may move to.
     *
     * @return list<self>
     */
    public function next(): array
    {
        return match ($this) {
            self::New => [self::Picking, self::Cancelled],
            self::Picking => [self::Ready, self::Cancelled],
            self::Ready => [self::Collected, self::Cancelled],
            self::Collected, self::Cancelled => [],
        };
    }

    public function canMoveTo(self $to): bool
    {
        return in_array($to, $this->next(), true);
    }

    /** Still to be worked (counts against its collection slot's capacity unless cancelled). */
    public function isOpen(): bool
    {
        return $this->next() !== [];
    }

    /** The order's time column set when it reaches this stage (null for new: `created_at`). */
    public function timeColumn(): ?string
    {
        return match ($this) {
            self::New => null,
            self::Picking => 'picked_at',
            self::Ready => 'ready_at',
            self::Collected => 'collected_at',
            self::Cancelled => 'cancelled_at',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
