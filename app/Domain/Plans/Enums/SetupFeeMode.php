<?php

namespace App\Domain\Plans\Enums;

/**
 * How a plan's setup fee is charged (P11, owner 2026-10-07): once per business (every plan before P11, the default)
 * or for each till, including tills added later (each added till gets its own setup fee invoice).
 */
enum SetupFeeMode: string
{
    case PerBusiness = 'perBusiness';
    case PerTill = 'perTill';

    public function label(): string
    {
        return match ($this) {
            self::PerBusiness => 'Once per business',
            self::PerTill => 'For each till',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $mode) => ['value' => $mode->value, 'label' => $mode->label()], self::cases());
    }
}
