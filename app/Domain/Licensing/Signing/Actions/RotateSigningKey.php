<?php

namespace App\Domain\Licensing\Signing\Actions;

use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\SigningKey;
use App\Domain\Shared\Actions\RecordAudit;

/**
 * New active key; the old one is retired and keeps verifying for the configured keep days.
 */
final class RotateSigningKey
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return array{key: SigningKey, retired: list<string>}
     *
     * @throws NoActiveSigningKey when there is nothing to rotate (use GenerateSigningKey first).
     */
    public function handle(): array
    {
        if (! $this->keys->hasActive()) {
            throw new NoActiveSigningKey;
        }

        $result = $this->keys->createActive();

        $this->audit->handle('licence_signing_key.rotated', meta: [
            'kid' => $result['key']->kid,
            'retiredKids' => $result['retired'],
        ]);

        return $result;
    }
}
