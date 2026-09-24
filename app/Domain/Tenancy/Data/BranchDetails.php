<?php

namespace App\Domain\Tenancy\Data;

use App\Domain\Tenancy\Enums\Nation;

/**
 * Editable details of a branch (mirrors the till's Branch entity).
 */
final readonly class BranchDetails
{
    public function __construct(
        public string $code,
        public string $name,
        public Nation $nation = Nation::England,
        public ?string $address = null,
        public ?string $phone = null,
        public ?string $vatNumber = null,
        public ?string $licensedHoursJson = null,
        public bool $isDrsReturnPoint = false,
        public ?string $areaM2 = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'code' => strtoupper($this->code),
            'name' => $this->name,
            'nation' => $this->nation,
            'address' => $this->address,
            'phone' => $this->phone,
            'vat_number' => $this->vatNumber,
            'licensed_hours_json' => $this->licensedHoursJson,
            'is_drs_return_point' => $this->isDrsReturnPoint,
            'area_m2' => $this->areaM2,
        ];
    }
}
