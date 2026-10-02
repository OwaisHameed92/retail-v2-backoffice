<?php

namespace App\Domain\Anomalies\Enums;

/**
 * How far from normal a finding is (module 6.6). High goes out straight away to users who chose it; the rest wait
 * for the 07:00 digest.
 */
enum AnomalySeverity: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }

    public function rank(): int
    {
        return match ($this) {
            self::Low => 1,
            self::Medium => 2,
            self::High => 3,
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Low => 'info',
            self::Medium => 'warning',
            self::High => 'danger',
        };
    }

    public function max(self $other): self
    {
        return $other->rank() > $this->rank() ? $other : $this;
    }

    public function bump(): self
    {
        return match ($this) {
            self::Low => self::Medium,
            default => self::High,
        };
    }

    /**
     * @return list<string>
     */
    public static function atLeast(self $min): array
    {
        return array_values(array_map(fn (self $s) => $s->value, array_filter(self::cases(), fn (self $s) => $s->rank() >= $min->rank())));
    }
}
