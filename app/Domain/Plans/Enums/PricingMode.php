<?php

namespace App\Domain\Plans\Enums;

/**
 * What a plan's recurring price is for (module 1.13): each live till, or each active branch (shop) whatever its
 * number of tills. A company can override it (billing settings).
 */
enum PricingMode: string
{
    case PerTill = 'perTill';
    case PerBranch = 'perBranch';

    public function label(): string
    {
        return match ($this) {
            self::PerTill => 'Per till',
            self::PerBranch => 'Per branch',
        };
    }

    /** "till" / "branch", for "£25.00 per till". */
    public function unit(): string
    {
        return match ($this) {
            self::PerTill => 'till',
            self::PerBranch => 'branch',
        };
    }

    public function units(int $count): string
    {
        return $count.' '.$this->unit().($count === 1 ? '' : ($this === self::PerBranch ? 'es' : 's'));
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()], self::cases());
    }
}
