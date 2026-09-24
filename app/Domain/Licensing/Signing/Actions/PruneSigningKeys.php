<?php

namespace App\Domain\Licensing\Signing\Actions;

use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Shared\Actions\RecordAudit;

/**
 * Deletes retired keys older than the keep period. Scheduled daily.
 */
final class PruneSigningKeys
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @return list<string> Kids deleted.
     */
    public function handle(): array
    {
        $pruned = $this->keys->prune();

        if ($pruned !== []) {
            $this->audit->handle('licence_signing_key.pruned', meta: ['kids' => $pruned]);
        }

        return $pruned;
    }
}
