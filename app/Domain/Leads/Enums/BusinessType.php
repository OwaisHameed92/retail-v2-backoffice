<?php

namespace App\Domain\Leads\Enums;

/**
 * The kind of shop a lead runs. camelCase values (contract convention).
 */
enum BusinessType: string
{
    case Convenience = 'convenience';
    case OffLicence = 'offLicence';
    case Newsagent = 'newsagent';
    case Grocery = 'grocery';
    case Forecourt = 'forecourt';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::Convenience => 'Convenience store',
            self::OffLicence => 'Off-licence',
            self::Newsagent => 'Newsagent',
            self::Grocery => 'Grocery',
            self::Forecourt => 'Forecourt',
            self::Other => 'Other',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $type) => ['value' => $type->value, 'label' => $type->label()], self::cases());
    }
}
