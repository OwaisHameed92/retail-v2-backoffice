<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Signing\KeyStore;
use App\Domain\Licensing\Signing\SigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;

/**
 * Lists licence signing keys. Never prints secret keys.
 */
class LicenceKeysListCommand extends Command
{
    protected $signature = 'licence:keys:list';

    protected $description = 'List licence token signing keys (kid, status, dates; no secrets)';

    public function handle(KeyStore $keys): int
    {
        $all = $keys->all();

        if ($all === []) {
            $this->warn('No licence signing keys. Run "php artisan licence:keys:generate".');

            return self::SUCCESS;
        }

        $now = CarbonImmutable::now('UTC');
        $keep = $keys->keepDays();

        $this->table(
            ['kid', 'status', 'signer cert', 'created (UTC)', 'retired (UTC)', 'verifies until (UTC)'],
            array_map(fn (SigningKey $key) => [
                $key->kid,
                $key->status($now, $keep)->value,
                $key->signerCert !== null ? 'yes' : 'no',
                $key->createdAt->format('Y-m-d H:i:s'),
                $key->retiredAt?->format('Y-m-d H:i:s') ?? '-',
                $key->expiresAt($keep)?->format('Y-m-d H:i:s') ?? '-',
            ], $all),
        );

        return self::SUCCESS;
    }
}
