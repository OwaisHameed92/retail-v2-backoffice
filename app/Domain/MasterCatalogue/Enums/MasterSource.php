<?php

namespace App\Domain\MasterCatalogue\Enums;

/** Where a master catalogue row last came from. */
enum MasterSource: string
{
    /** The ~600 demo convenience products (LoadStarterSet): synthetic barcodes for most lines, a starting point only. */
    case Starter = 'starter';
    /** An admin CSV load, e.g. a licensed supplier file. */
    case Import = 'import';
    /** A till's unknown barcode, approved by an admin. */
    case Contribution = 'contribution';
    /** Added or edited by hand on /admin/catalogue. */
    case Admin = 'admin';

    public function label(): string
    {
        return match ($this) {
            self::Starter => 'Starter set',
            self::Import => 'Imported',
            self::Contribution => 'From tills',
            self::Admin => 'Added by SSPOS',
        };
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    public static function options(): array
    {
        return array_map(fn (self $s) => ['value' => $s->value, 'label' => $s->label()], self::cases());
    }
}
