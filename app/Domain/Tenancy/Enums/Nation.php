<?php

namespace App\Domain\Tenancy\Enums;

use App\Domain\Shared\Country\Country;

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
     * The nations the country profile offers on the shop forms (phase P9): all four on GB, none on PK, where the field
     * is hidden (the contract has no Pakistani value; a shop keeps the column default).
     *
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        $offered = app(Country::class)->nations();
        $cases = array_values(array_filter(self::cases(), fn (self $nation) => in_array($nation->value, $offered, true)));

        return array_map(fn (self $nation) => ['value' => $nation->value, 'label' => $nation->label()], $cases);
    }

    /** True when the country profile shows the nation on shop forms and pages (GB). */
    public static function shown(): bool
    {
        return app(Country::class)->nations() !== [];
    }

    /** The label where the profile shows nations ("England"), null elsewhere. */
    public function shownLabel(): ?string
    {
        return self::shown() ? $this->label() : null;
    }
}
