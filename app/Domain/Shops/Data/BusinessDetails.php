<?php

namespace App\Domain\Shops\Data;

/**
 * What the owner may change about the business from the portal (module 4.7). The till's Company members (name,
 * legal name, VAT and company numbers, address, phone, email) reach every till in the pull; town, postcode and
 * receipt footer go in the licence key. Status, plan, limits, notes and the business type stay with admin.
 */
final readonly class BusinessDetails
{
    /** The columns a portal save may write. */
    public const COLUMNS = ['name', 'legal_name', 'vat_number', 'company_number', 'address', 'town', 'postcode', 'phone', 'email', 'receipt_footer'];

    public function __construct(
        public string $name,
        public ?string $legalName = null,
        public ?string $vatNumber = null,
        public ?string $companyNumber = null,
        public ?string $address = null,
        public ?string $town = null,
        public ?string $postcode = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $receiptFooter = null,
    ) {}

    /**
     * @param  array<string, mixed>  $input  validated snake_case input
     */
    public static function fromArray(array $input): self
    {
        $text = fn (string $key) => isset($input[$key]) && trim((string) $input[$key]) !== '' ? trim((string) $input[$key]) : null;
        $upper = fn (string $key) => $text($key) === null ? null : mb_strtoupper(str_replace(' ', '', (string) $text($key)));

        return new self(
            name: trim((string) ($input['name'] ?? '')),
            legalName: $text('legal_name'),
            vatNumber: $upper('vat_number'),
            companyNumber: $upper('company_number'),
            address: $text('address'),
            town: $text('town'),
            postcode: $text('postcode') === null ? null : mb_strtoupper((string) $text('postcode')),
            phone: $text('phone'),
            email: $text('email') === null ? null : mb_strtolower((string) $text('email')),
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
            'legal_name' => $this->legalName,
            'vat_number' => $this->vatNumber,
            'company_number' => $this->companyNumber,
            'address' => $this->address,
            'town' => $this->town,
            'postcode' => $this->postcode,
            'phone' => $this->phone,
            'email' => $this->email,
            'receipt_footer' => $this->receiptFooter,
        ];
    }
}
