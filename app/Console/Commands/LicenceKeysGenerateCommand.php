<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Signing\Actions\GenerateSigningKey;
use App\Domain\Licensing\Signing\Exceptions\ActiveSigningKeyExists;
use Illuminate\Console\Command;

/**
 * Creates the first Ed25519 licence signing key. Prints only the kid and public key.
 */
class LicenceKeysGenerateCommand extends Command
{
    protected $signature = 'licence:keys:generate
        {--force : Replace the active key (it is retired and keeps verifying for the keep period)}';

    protected $description = 'Create the first licence token signing key (Ed25519)';

    public function handle(GenerateSigningKey $generate): int
    {
        try {
            $key = $generate->handle((bool) $this->option('force'));
        } catch (ActiveSigningKeyExists $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Licence signing key {$key->kid} created and active.");
        $this->line("Public key (x): {$key->x()}");
        $this->line('Next: php artisan licence:keys:handover, send it to the SSPOS owner, then licence:keys:import-cert.');

        return self::SUCCESS;
    }
}
