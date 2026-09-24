<?php

namespace App\Domain\Licensing\Signing\Actions;

use App\Domain\Licensing\Signing\Exceptions\ActiveSigningKeyExists;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\SigningKey;
use App\Domain\Shared\Actions\RecordAudit;

/**
 * Creates the first licence signing key. With $force it also replaces an existing active key (which is
 * retired, exactly like a rotation).
 */
final class GenerateSigningKey
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws ActiveSigningKeyExists
     */
    public function handle(bool $force = false): SigningKey
    {
        if (! $force && $this->keys->hasActive()) {
            throw new ActiveSigningKeyExists($this->keys->active()->kid);
        }

        $result = $this->keys->createActive();

        $this->audit->handle('licence_signing_key.generated', meta: [
            'kid' => $result['key']->kid,
            'retiredKids' => $result['retired'],
            'forced' => $force,
        ]);

        return $result['key'];
    }
}
