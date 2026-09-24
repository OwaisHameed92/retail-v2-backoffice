<?php

namespace App\Domain\Tenancy\Data;

use Illuminate\Support\Carbon;

/**
 * Editable business details of a company (till Company fields + portal contact/notes).
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
        ];
    }
}
