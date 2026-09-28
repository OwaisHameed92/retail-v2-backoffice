<?php

namespace App\Domain\Leads\Enums;

use App\Domain\Tenancy\Enums\BusinessType as TillBusinessType;

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
     * The till's BusinessType name (public trial form, module 1.10) as a lead's type; kinds a lead has no
     * type for are Other (the form keeps the till's name in the lead's message).
     */
    public static function fromContract(TillBusinessType $type): self
    {
        return match ($type) {
            TillBusinessType::ConvenienceOffLicence => self::Convenience,
            TillBusinessType::Newsagent => self::Newsagent,
            TillBusinessType::GroceryHalalButcher => self::Grocery,
            default => self::Other,
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
