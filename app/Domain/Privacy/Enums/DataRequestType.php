<?php

namespace App\Domain\Privacy\Enums;

/** What a customer asked for under UK GDPR (module 7.7). */
enum DataRequestType: string
{
    /** Right of access (a subject access request): a copy of everything held about them. */
    case Export = 'export';

    /** Right to erasure: their details anonymised; money records kept with the customer id. */
    case Erasure = 'erasure';

    public function label(): string
    {
        return match ($this) {
            self::Export => 'Data export',
            self::Erasure => 'Erasure',
        };
    }
}
