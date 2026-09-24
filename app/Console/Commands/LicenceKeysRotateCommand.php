<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Signing\Actions\RotateSigningKey;
use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\KeyStore;
use Illuminate\Console\Command;

/**
 * Makes a new active licence signing key; the old one is retired but still verifies for the keep period.
 */
class LicenceKeysRotateCommand extends Command
{
    protected $signature = 'licence:keys:rotate';

    protected $description = 'Rotate the licence token signing key (old key kept for verification)';

    public function handle(RotateSigningKey $rotate, KeyStore $keys): int
    {
        try {
            $result = $rotate->handle();
        } catch (NoActiveSigningKey $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Licence signing key {$result['key']->kid} is now active.");
        $this->line("Public key (x): {$result['key']->x()}");

        foreach ($result['retired'] as $kid) {
            $this->line("Retired {$kid}: still verifies for {$keys->keepDays()} days, then licence:keys:prune removes it.");
        }

        return self::SUCCESS;
    }
}
