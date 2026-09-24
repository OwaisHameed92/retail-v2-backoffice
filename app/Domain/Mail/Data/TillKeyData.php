<?php

namespace App\Domain\Mail\Data;

use SensitiveParameter;

/**
 * One till and its licence key, for the welcome email only. The key is a secret: it is shown in that email
 * once and never logged or stored anywhere else by the mail module.
 */
final readonly class TillKeyData
{
    public function __construct(
        public string $branchName,
        public string $tillName,
        #[SensitiveParameter] public string $licenceKey,
    ) {}

    /** Hide the key from dumps and debug output. */
    public function __debugInfo(): array
    {
        return ['branchName' => $this->branchName, 'tillName' => $this->tillName, 'licenceKey' => '[redacted]'];
    }
}
