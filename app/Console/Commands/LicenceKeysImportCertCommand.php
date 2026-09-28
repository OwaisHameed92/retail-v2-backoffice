<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Signing\Actions\ImportSignerCertificate;
use App\Domain\Licensing\Signing\Exceptions\BadSignerCertificate;
use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use Illuminate\Console\Command;

/**
 * Imports the signer certificate the SSPOS owner sent back for our public-key hand-over (contract §17.17).
 */
class LicenceKeysImportCertCommand extends Command
{
    protected $signature = 'licence:keys:import-cert {cert : The SSPOSCERT1… string from the SSPOS owner}';

    protected $description = 'Import the signer certificate for the active licence signing key';

    public function handle(ImportSignerCertificate $import): int
    {
        try {
            $key = $import->handle((string) $this->argument('cert'));
        } catch (BadSignerCertificate|NoActiveSigningKey $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Signer certificate imported for {$key->kid}. Every new licence token now carries it.");

        return self::SUCCESS;
    }
}
