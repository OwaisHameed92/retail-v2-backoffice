<?php

namespace App\Domain\Shops\Data;

/**
 * What a business may change about one of its shops from the portal (module 4.7). The code, nation, active flag,
 * licensed hours and licence settings stay with Switch & Save (admin). Name, address, phone and VAT number are the
 * till's Branch members and reach that shop's till in the pull; town, postcode and receipt footer go in its key.
 */
final readonly class ShopDetails
{
    /** The columns a portal save may write. */
    public const COLUMNS = ['name', 'address', 'town', 'postcode', 'phone', 'vat_number', 'receipt_footer'];

    public function __construct(
        public string $name,
        public ?string $address = null,
        public ?string $town = null,
        public ?string $postcode = null,
        public ?string $phone = null,
        public ?string $vatNumber = null,
        public ?string $receiptFooter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated snake_case input
     */
    public static function fromArray(array $input): self
    {
        $text = fn (string $key) => isset($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;

        return new self(
            name: trim((string) ($input['name'] ?? '')),
            address: $text('address'),
            town: $text('town'),
            postcode: $text('postcode') === null ? null : mb_strtoupper((string) $text('postcode')),
            phone: $text('phone'),
            vatNumber: $text('vat_number') === null ? null : mb_strtoupper(str_replace(' ', '', (string) $text('vat_number'))),
            receiptFooter: $text('receipt_footer'),
        );
    }

    /**
     * @return array<string, string|null>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'address' => $this->address,
            'town' => $this->town,
            'postcode' => $this->postcode,
            'phone' => $this->phone,
            'vat_number' => $this->vatNumber,
            'receipt_footer' => $this->receiptFooter,
        ];
    }
}
