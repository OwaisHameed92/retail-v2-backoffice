<?php

namespace App\Domain\Mail\Data;

use Illuminate\Support\Carbon;
use SensitiveParameter;

/**
 * An invitation to a business's portal (module 4.1). The URL holds a one-time token: it is never logged.
 */
final readonly class PortalInvitationData
{
    public function __construct(
        public string $name,
        public string $email,
        public string $businessName,
        public string $roleLabel,
        public ?string $branchName,
        public ?string $inviterName,
        #[SensitiveParameter] public string $url,
        public Carbon $expiresAt,
        public ?string $companyId = null,
    ) {}

    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'businessName' => $this->businessName, 'url' => '[redacted]'];
    }
}
