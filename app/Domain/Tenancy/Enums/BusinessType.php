<?php

namespace App\Domain\Tenancy\Enums;

/**
 * The kind of shop, as the till's first-run wizard knows it (contract v1.3.1 §17.2 `company.businessType`,
 * `BusinessType` names in common.schema.json). Values are the till's names exactly (PascalCase), because the
 * till compares them as written; blank or unknown = the wizard asks.
 */
enum BusinessType: string
{
    case ConvenienceOffLicence = 'ConvenienceOffLicence';
    case Newsagent = 'Newsagent';
    case GroceryHalalButcher = 'GroceryHalalButcher';
    case ClothingFootwear = 'ClothingFootwear';
    case PhoneElectronics = 'PhoneElectronics';
    case Salon = 'Salon';
    case DryCleaner = 'DryCleaner';
    case CashAndCarry = 'CashAndCarry';
    case Pharmacy = 'Pharmacy';
    case Other = 'Other';

    public function label(): string
    {
        return match ($this) {
            self::ConvenienceOffLicence => 'Convenience store / off-licence',
            self::Newsagent => 'Newsagent',
            self::GroceryHalalButcher => 'Grocery / halal butcher',
            self::ClothingFootwear => 'Clothing and footwear',
            self::PhoneElectronics => 'Phones and electronics',
            self::Salon => 'Salon',
            self::DryCleaner => 'Dry cleaner',
            self::CashAndCarry => 'Cash and carry',
            self::Pharmacy => 'Pharmacy',
            self::Other => 'Other',
        };
    }

    /**
     * A lead's business type (module 1.6) as the till's: forecourts and anything else are Other.
     */
    public static function fromLead(?string $leadType): ?self
    {
        return match ($leadType) {
            'convenience', 'offLicence' => self::ConvenienceOffLicence,
            'newsagent' => self::Newsagent,
            'grocery' => self::GroceryHalalButcher,
            null, '' => null,
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
