<?php

namespace App\Domain\OnlineStore\Legal;

use App\Domain\OnlineStore\Models\OnlineStore;
use App\Domain\OnlineStore\Support\AddressLine;
use App\Domain\Tenancy\Models\Company;

/**
 * Who sells through an online store (OS-11, audit OSA-2): the trader details UK law asks an online shop to show
 * (Electronic Commerce Regulations 2002 reg. 6, Consumer Contracts Regulations 2013 sch. 2, UK GDPR art. 13):
 * trading name, legal entity name, geographic address, company number (companies only), VAT number (when registered),
 * contact email and phone. Saved per store (SaveStoreLegal) on the portal's "Legal & contact" tab.
 *
 * - `for()`: what the public pages show: the store's saved details, each empty one filled from the business record.
 * - `suggested()`: the business record's values, which the portal form starts from.
 * - `missing()`: the required details the store has not saved yet; going live needs none missing (SetStoreLive).
 */
final readonly class StoreTrader
{
    /** The store columns, in form order. */
    public const FIELDS = ['trading_name', 'legal_name', 'legal_address', 'company_number', 'vat_number', 'contact_email', 'contact_phone'];

    /** Saved before going live, with the checklist's words. */
    public const REQUIRED = [
        'trading_name' => 'trading name',
        'legal_name' => 'legal name',
        'legal_address' => 'business address',
        'contact_email' => 'contact email',
    ];

    public function __construct(
        public string $tradingName,
        public string $legalName,
        public ?string $address,
        public ?string $companyNumber,
        public ?string $vatNumber,
        public ?string $email,
        public ?string $phone,
    ) {}

    public static function for(OnlineStore $store, Company $company): self
    {
        $suggested = self::suggested($company);
        $value = fn (string $field): ?string => self::clean($store->getAttribute($field)) ?? $suggested[$field];
        $trading = $value('trading_name') ?? (string) $company->name;

        return new self($trading, $value('legal_name') ?? $trading, $value('legal_address'), $value('company_number'), $value('vat_number'),
            $value('contact_email'), $value('contact_phone'));
    }

    /**
     * The business record's details, as the form's starting values.
     *
     * @return array<string, string|null>
     */
    public static function suggested(Company $company): array
    {
        $address = AddressLine::from($company->address, $company->town, $company->postcode);
        $email = self::clean($company->email);

        return [
            'trading_name' => self::clean($company->name),
            'legal_name' => self::clean($company->legal_name) ?? self::clean($company->name),
            'legal_address' => $address,
            'company_number' => self::clean($company->company_number),
            'vat_number' => self::clean($company->vat_number),
            'contact_email' => $email !== null && filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : null,
            'contact_phone' => self::clean($company->phone),
        ];
    }

    /**
     * The required details the store has not saved, as the checklist names them.
     *
     * @return list<string>
     */
    public static function missing(OnlineStore $store): array
    {
        return array_values(array_filter(self::REQUIRED, fn (string $label, string $field) => self::clean($store->getAttribute($field)) === null,
            ARRAY_FILTER_USE_BOTH));
    }

    /**
     * @return array{tradingName: string, legalName: string, address: string|null, companyNumber: string|null, vatNumber: string|null, email: string|null, phone: string|null}
     */
    public function toArray(): array
    {
        return [
            'tradingName' => $this->tradingName,
            'legalName' => $this->legalName,
            'address' => $this->address,
            'companyNumber' => $this->companyNumber,
            'vatNumber' => $this->vatNumber,
            'email' => $this->email,
            'phone' => $this->phone,
        ];
    }

    /** "Kirkgate Ltd, trading as Kirkgate Market" or just the name when they are the same. */
    public function who(): string
    {
        return strcasecmp($this->legalName, $this->tradingName) === 0 ? $this->tradingName : "{$this->legalName}, trading as {$this->tradingName}";
    }

    private static function clean(mixed $value): ?string
    {
        $value = is_scalar($value) ? trim((string) $value) : '';

        return $value === '' ? null : $value;
    }
}
