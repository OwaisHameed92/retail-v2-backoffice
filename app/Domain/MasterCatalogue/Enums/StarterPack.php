<?php

namespace App\Domain\MasterCatalogue\Enums;

use App\Domain\Tenancy\Enums\BusinessType;

/**
 * The kinds of shop a new business picks from for its starter pack: each suggests the master catalogue departments
 * that kind of shop usually stocks (department names as the master catalogue files them; see
 * docs/master-catalogue.md). The owner can untick any department before adding.
 */
enum StarterPack: string
{
    case Convenience = 'convenience';
    case OffLicence = 'offLicence';
    case Newsagent = 'newsagent';
    case Grocery = 'grocery';

    public function label(): string
    {
        return match ($this) {
            self::Convenience => 'Convenience store',
            self::OffLicence => 'Off-licence',
            self::Newsagent => 'Newsagent',
            self::Grocery => 'Grocery',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Convenience => 'A full range: groceries, chilled and frozen, drinks, alcohol, tobacco, snacks, news and household.',
            self::OffLicence => 'Beers, wines and spirits with soft drinks, tobacco and snacks.',
            self::Newsagent => 'Newspapers and magazines, confectionery, soft drinks, tobacco and everyday essentials.',
            self::Grocery => 'Cupboard staples, chilled, frozen, fresh, household and soft drinks.',
        };
    }

    /**
     * Master catalogue departments this kind of shop starts with; null = every department.
     *
     * @return list<string>|null
     */
    public function departments(): ?array
    {
        return match ($this) {
            self::Convenience => null,
            self::OffLicence => ['Beers, wines and spirits', 'Soft drinks', 'Tobacco and vaping', 'Confectionery and snacks'],
            self::Newsagent => ['Newspapers and magazines', 'Confectionery and snacks', 'Soft drinks', 'Tobacco and vaping', 'Household and health'],
            self::Grocery => ['Grocery', 'Chilled', 'Frozen', 'Fresh fruit, veg and bakery', 'Household and health', 'Soft drinks'],
        };
    }

    /** The pack suggested for a business from its till business type. */
    public static function forBusiness(?BusinessType $type): self
    {
        return match ($type) {
            BusinessType::Newsagent => self::Newsagent,
            BusinessType::GroceryHalalButcher, BusinessType::CashAndCarry => self::Grocery,
            default => self::Convenience,
        };
    }

    /**
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $p) => ['value' => $p->value, 'label' => $p->label(), 'description' => $p->description()], self::cases());
    }
}
