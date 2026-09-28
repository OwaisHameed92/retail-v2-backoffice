<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\Sspos\PublicKeyHandover;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Prints the public-key hand-over JSON for the active key (public key only), optionally writing it to a file.
 */
class LicenceKeysHandoverCommand extends Command
{
    protected $signature = 'licence:keys:handover {--path= : Also write the JSON to this file}';

    protected $description = 'Print the public-key hand-over JSON for the SSPOS owner (no secrets)';

    public function handle(KeyStore $keys, PublicKeyHandover $handover): int
    {
        try {
            $json = json_encode($handover->for($keys->active()), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        } catch (NoActiveSigningKey $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($json);

        $path = $this->option('path');

        if (is_string($path) && $path !== '') {
            File::put($path, $json."\n");
            $this->info("Written to {$path}.");
        }

        return self::SUCCESS;
    }
}
