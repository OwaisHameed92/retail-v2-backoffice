<?php

namespace App\Domain\Notifications\Enums;

/**
 * How a portal user hears about one alert type (module 7.8): not at all, in the 07:00 daily digest, or by an email
 * as soon as it happens (urgent types only). The bell lists everything that is not off.
 */
enum AlertDelivery: string
{
    case Off = 'off';
    case Digest = 'digest';
    case Immediate = 'immediate';

    public function label(): string
    {
        return match ($this) {
            self::Off => 'Off',
            self::Digest => 'Daily digest',
            self::Immediate => 'Straight away',
        };
    }
}
