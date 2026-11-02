<?php

namespace App\Domain\TillImages\Enums;

/**
 * Where the till got a product picture (IMG-2, till 0.1.83 header `X-SSPOS-Image-Source`): `own` the shop chose it,
 * `library` Open Food Facts by barcode (CC BY-SA: credited on the public store), `found` a web image search (copyright
 * unknown), `unknown` a picture from before the till sent the header. The public store shows `own` and `library`;
 * `found` and `unknown` only when the store's "Show found pictures" is on. The portal's own pages show every picture.
 */
enum ProductImageOrigin: string
{
    case Own = 'own';
    case Library = 'library';
    case Found = 'found';
    case Unknown = 'unknown';

    public const HEADER = 'X-SSPOS-Image-Source';

    public const LIBRARY_CREDIT = 'Photo: Open Food Facts (CC BY-SA)';

    /** The store footer's line while the business holds Open Food Facts pictures. */
    public const FOOTER_CREDIT = 'Some product photos: Open Food Facts (CC BY-SA)';

    /** The header's value, trimmed and in any case; missing, empty or anything else is `unknown` (never refused). */
    public static function fromHeader(mixed $value): self
    {
        return is_string($value) ? (self::tryFrom(strtolower(trim($value))) ?? self::Unknown) : self::Unknown;
    }

    /**
     * The origins the public online store shows, as stored values.
     *
     * @return list<string>
     */
    public static function shownOnStore(bool $showFound): array
    {
        return array_map(fn (self $origin) => $origin->value, $showFound ? self::cases() : [self::Own, self::Library]);
    }

    /** The credit shown with the picture on the public store, or null. */
    public function credit(): ?string
    {
        return $this === self::Library ? self::LIBRARY_CREDIT : null;
    }

    /** For the portal's product form. */
    public function label(): string
    {
        return match ($this) {
            self::Own => 'Shop\'s own',
            self::Library => 'Open Food Facts',
            self::Found => 'Found online',
            self::Unknown => 'Unknown source',
        };
    }
}
