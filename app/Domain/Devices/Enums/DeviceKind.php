<?php

namespace App\Domain\Devices\Enums;

/**
 * The kinds of device on the admin Devices page: a till (one licence) or a handheld (a signed-in PDA app).
 */
enum DeviceKind: string
{
    case Till = 'till';
    case Handheld = 'handheld';

    public function label(): string
    {
        return match ($this) {
            self::Till => 'Till',
            self::Handheld => 'Handheld',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $kind) => ['value' => $kind->value, 'label' => $kind->label()], self::cases());
    }
}
