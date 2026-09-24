<?php

namespace App\Domain\Leads\Data;

use App\Domain\Leads\Enums\BusinessType;
use App\Domain\Leads\Enums\LeadSource;
use Illuminate\Support\Str;

/**
 * What we know about a prospect: used by CreateLead (admin form, public trial form 1.10) and UpdateLead.
 * Values are cleaned here (trimmed, blank → null, email lower case, postcode upper case).
 */
final readonly class LeadDetails
{
    public ?string $email;

    public ?string $phone;

    public ?string $town;

    public ?string $postcode;

    public ?string $currentSystem;

    public ?string $message;

    public string $businessName;

    public string $contactName;

    /**
     * @param  array<string, string>|null  $utm  utm_source, utm_medium… from the public form (1.10).
     */
    public function __construct(
        string $businessName,
        string $contactName,
        ?string $email = null,
        ?string $phone = null,
        ?string $town = null,
        ?string $postcode = null,
        public int $shopsCount = 1,
        public int $tillsCount = 1,
        public BusinessType $businessType = BusinessType::Convenience,
        ?string $currentSystem = null,
        ?string $message = null,
        public LeadSource $source = LeadSource::Website,
        public bool $consentMarketing = false,
        public ?array $utm = null,
        public ?string $ip = null,
    ) {
        $this->businessName = trim($businessName);
        $this->contactName = trim($contactName);
        $this->email = self::clean($email) === null ? null : Str::lower((string) self::clean($email));
        $this->phone = self::clean($phone);
        $this->town = self::clean($town);
        $this->postcode = self::clean($postcode) === null ? null : self::postcode((string) self::clean($postcode));
        $this->currentSystem = self::clean($currentSystem);
        $this->message = self::clean($message);
    }

    /**
     * The detail columns (not status, assignment or conversion).
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'business_name' => $this->businessName,
            'contact_name' => $this->contactName,
            'email' => $this->email,
            'phone' => $this->phone,
            'town' => $this->town,
            'postcode' => $this->postcode,
            'shops_count' => $this->shopsCount,
            'tills_count' => $this->tillsCount,
            'business_type' => $this->businessType,
            'current_system' => $this->currentSystem,
            'message' => $this->message,
            'source' => $this->source,
            'consent_marketing' => $this->consentMarketing,
            'utm' => $this->utm === [] ? null : $this->utm,
            'ip' => $this->ip,
        ];
    }

    private static function clean(?string $value): ?string
    {
        $value = $value === null ? null : trim($value);

        return $value === '' ? null : $value;
    }

    /** "ls16bx" → "LS1 6BX"; anything that is not a UK postcode shape is kept as typed, upper case. */
    private static function postcode(string $value): string
    {
        $compact = strtoupper((string) preg_replace('/\s+/', '', $value));

        return preg_match('/^[A-Z]{1,2}\d[A-Z\d]?\d[A-Z]{2}$/', $compact) === 1
            ? substr($compact, 0, -3).' '.substr($compact, -3)
            : strtoupper($value);
    }
}
