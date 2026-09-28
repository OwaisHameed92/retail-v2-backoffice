<?php

namespace App\Domain\Licensing\Signing\Actions;

use App\Domain\Licensing\Signing\Exceptions\BadSignerCertificate;
use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\SigningKey;
use App\Domain\Licensing\Signing\Sspos\SignerCertificate;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;

/**
 * Stores the owner's signer certificate (contract §17.17) on the active key after the till's checks:
 * `SSPOSCERT1.` format, a configured approver's valid signature, kid and publicKey equal to the active key,
 * and not already ended.
 */
final class ImportSignerCertificate
{
    public function __construct(
        private readonly KeyStore $keys,
        private readonly Config $config,
        private readonly RecordAudit $audit,
    ) {}

    /**
     * @throws BadSignerCertificate
     * @throws NoActiveSigningKey
     */
    public function handle(string $certificate): SigningKey
    {
        $active = $this->keys->active();

        /** @var array<string, string> $approvers */
        $approvers = (array) $this->config->get('licence.approvers', []);

        $cert = SignerCertificate::parse($certificate)->check($approvers);

        if ($cert->kid !== $active->kid) {
            throw new BadSignerCertificate("Certificate is for {$cert->kid}; the active key is {$active->kid}.");
        }

        $cert->assertFor($active->kid, $active->publicKey);

        if ($cert->expiresAt !== null && $cert->expiresAt->lessThanOrEqualTo(CarbonImmutable::now('UTC'))) {
            throw new BadSignerCertificate('Certificate has already ended.');
        }

        $key = $this->keys->saveSignerCert($active->kid, $cert->value);

        $this->audit->handle('licence_signing_key.certified', meta: [
            'kid' => $cert->kid,
            'approvedBy' => $cert->approvedBy,
            'name' => $cert->name,
            'expiresAt' => $cert->expiresAt?->toIso8601ZuluString(),
        ]);

        return $key;
    }
}
