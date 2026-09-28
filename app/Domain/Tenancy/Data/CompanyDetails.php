<?php

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\BusinessType;
use Illuminate\Support\Carbon;

/**
 * Editable business details of a company (till Company fields + portal contact/notes + the licence key's shop
 * details, module 1.11).
 */
final readonly class CompanyDetails
{
    public function __construct(
        public string $name,
        public ?string $legalName = null,
        public ?string $vatNumber = null,
        public ?string $companyNumber = null,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $email = null,
        public ?string $contactName = null,
        public ?string $notes = null,
        public ?Carbon $trialEndsAt = null,
        /** Module 1.11: the key's `company` block (contract §17.2). */
        public ?BusinessType $businessType = null,
        public ?string $town = null,
        public ?string $postcode = null,
        public ?string $ownerName = null,
        public ?string $receiptFooter = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name,
            'legal_name' => $this->legalName,
            'vat_number' => $this->vatNumber,
            'company_number' => $this->companyNumber,
            'address' => $this->address,
            'phone' => $this->phone,
            'email' => $this->email,
            'contact_name' => $this->contactName,
            'notes' => $this->notes,
            'trial_ends_at' => $this->trialEndsAt,
            'business_type' => $this->businessType,
            'town' => $this->town,
            'postcode' => $this->postcode,
            'owner_name' => $this->ownerName,
            'receipt_footer' => $this->receiptFooter,
        ];
    }
}
