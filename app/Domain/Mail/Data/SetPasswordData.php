<?php

namespace App\Domain\Mail\Data;

use SensitiveParameter;

/**
 * A "set your password" (first time) or "reset your password" link for a portal user.
 * The URL holds a one-time token: it is never logged.
 */
final readonly class SetPasswordData
{
    public function __construct(
        public string $name,
        public string $email,
        #[SensitiveParameter] public string $url,
        public int $expiresInMinutes,
        public bool $firstTime = false,
        public ?string $businessName = null,
        public ?string $companyId = null,
    ) {}

    public function __debugInfo(): array
    {
        return ['name' => $this->name, 'email' => $this->email, 'url' => '[redacted]', 'firstTime' => $this->firstTime];
    }
}
