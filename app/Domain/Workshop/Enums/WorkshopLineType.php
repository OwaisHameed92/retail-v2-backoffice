<?php

namespace App\Domain\Workshop\Enums;

/**
 * A workshop order line's kind (WORKSHOP-BIKEBOOK.md §5.1, proposed enum `workshopLineType`): `part` has a product,
 * `labour` and `custom` are booked by the till on the shop's labour / misc product, `fee` likewise.
 */
enum WorkshopLineType: string
{
    case Part = 'part';
    case Labour = 'labour';
    case Custom = 'custom';
    case Fee = 'fee';

    /** BikeBook's `lineType` ("Product", "Custom", "Labour", …) to ours; a line with a product is always a part. */
    public static function fromBikeBook(?string $value, bool $hasProduct): self
    {
        if ($hasProduct) {
            return self::Part;
        }

        return match (strtolower(trim((string) $value))) {
            'labour', 'labor', 'service', 'services' => self::Labour,
            'fee', 'shipping', 'delivery', 'charge' => self::Fee,
            default => self::Custom,
        };
    }

    /** Back to BikeBook's words in our SalesOrder answer. */
    public function bikeBook(): string
    {
        return match ($this) {
            self::Part => 'Product',
            self::Labour => 'Labour',
            self::Custom => 'Custom',
            self::Fee => 'Fee',
        };
    }
}
