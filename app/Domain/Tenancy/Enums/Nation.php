<?php

namespace App\Domain\Tenancy\Enums;

/**
 * UK nation a branch trades in (drives DRS, licensing and VAT rules on the till). camelCase contract values.
 */
enum Nation: string
{
    case England = 'england';
    case Scotland = 'scotland';
    case Wales = 'wales';
    case NorthernIreland = 'northernIreland';

    public function label(): string
    {
        return match ($this) {
            self::England => 'England',
            self::Scotland => 'Scotland',
            self::Wales => 'Wales',
            self::NorthernIreland => 'Northern Ireland',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $nation) => ['value' => $nation->value, 'label' => $nation->label()], self::cases());
    }
}
