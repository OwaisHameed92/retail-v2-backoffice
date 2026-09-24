<?php

namespace App\Domain\Leads\Data;

use Carbon\CarbonInterface;

/**
 * Another lead or a tenant that shares a lead's email or phone number.
 */
final readonly class DuplicateMatch
{
    /**
     * @param  'lead'|'tenant'  $type
     * @param  list<string>  $matchedOn  "email", "phone"
     */
    public function __construct(
        public string $type,
        public string $id,
        public string $name,
        public string $status,
        public array $matchedOn,
        public ?CarbonInterface $createdAt = null,
    ) {}

    /** "same email as tenant Khan Mini Mart" */
    public function describe(): string
    {
        $on = implode(' and ', $this->matchedOn);

        return "same {$on} as ".($this->type === 'tenant' ? 'tenant' : 'lead')." {$this->name}";
    }

    /**
     * @return array{type: string, id: string, name: string, status: string, matchedOn: list<string>, createdAt: string|null}
     */
    public function toArray(): array
    {
        return [
            'type' => $this->type,
            'id' => $this->id,
            'name' => $this->name,
            'status' => $this->status,
            'matchedOn' => $this->matchedOn,
            'createdAt' => $this->createdAt?->toIso8601String(),
        ];
    }
}
