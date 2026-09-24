<?php

namespace App\Domain\Licensing\Data;

use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use SensitiveParameter;

/**
 * A licence and its plain key, returned only by the action that created the key (IssueLicence, ReissueKey).
 * The key must go straight into the response or the welcome email and nowhere else: never log, store or queue
 * it outside an encrypted mailable.
 */
final readonly class IssuedLicence
{
    public function __construct(
        public Licence $licence,
        #[SensitiveParameter] public LicenceKey $key,
        /** True when an older key of the same licence stopped working (ReissueKey). */
        public bool $replacedKey = false,
    ) {}

    /** "SSP-7K2Q-9DMF-3XRA-P8TN" */
    public function plainKey(): string
    {
        return $this->key->formatted();
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        return ['licence' => $this->licence->id, 'key' => $this->licence->maskedKey(), 'replacedKey' => $this->replacedKey];
    }
}
