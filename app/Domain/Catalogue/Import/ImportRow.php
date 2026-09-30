<?php

namespace App\Domain\Catalogue\Import;

/**
 * One CSV row as read (RowInterpreter): how to find the product, the product columns it sets (only mapped, non-empty
 * cells: an empty cell keeps the product's value), the department / category / VAT as written, and format errors.
 */
final class ImportRow
{
    /**
     * @param  array<string, mixed>  $attributes
     * @param  list<string>  $errors
     */
    public function __construct(
        public readonly int $line,
        public readonly ?string $barcode,
        public readonly ?string $sku,
        public array $attributes,
        public readonly ?string $department,
        public readonly ?string $category,
        public readonly ?string $vat,
        public array $errors = [],
    ) {}

    public function key(): string
    {
        return $this->barcode ?? 'sku:'.$this->sku;
    }
}
