<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Api\Simulator\SimulatedTill;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Signing\KeyStore;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Throwable;

/**
 * A pretend till for demos and the EPOS team (module 1.5, contract v1.4.1 §17.15). Calls the real licence API
 * over HTTP with the till's headers, prints the reply, checks a returned SSPOS1 token like the till and says
 * what the till would do.
 *
 *     php artisan licence:simulate activate --key=SSP-7K2Q-9DMF-3XRA-P8T5
 *     php artisan licence:simulate validate --licence=01K5T0Q8C4000000000000Y101 --token=SSPOS1.…
 *     php artisan licence:simulate deactivate --register=01K5T0Q8C4000000000000R001 --url=https://portal.test
 *
 * The key is only printed masked (SSP-••••-••••-••••-P8T5).
 */
class LicenceSimulateCommand extends Command
{
    protected $signature = 'licence:simulate
        {action=activate : activate, validate or deactivate}
        {--key= : The licence key, as typed on the till (activate)}
        {--licence= : The licenceId from the activate reply (validate)}
        {--token= : The token the till holds (validate; without it the portal sends a new one)}
        {--register= : The till\'s own registerId (existingIds, deactivate)}
        {--install= : The PC\'s installId (default: a fixed id for this machine)}
        {--install-code= : The PC\'s install code (default: derived like the install id)}
        {--name=SIMULATED-TILL : The PC name}
        {--url= : Portal base URL (default: APP_URL)}';

    protected $description = 'Act as a till against the licence API (activate, validate, deactivate) and check the SSPOS1 token like the till';

    private const ACTIONS = ['activate', 'validate', 'deactivate'];

    public function handle(): int
    {
        $action = (string) $this->argument('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('The action must be one of: '.implode(', ', self::ACTIONS).'.');

            return self::INVALID;
        }

        $till = $this->till();
        $url = (string) ($this->option('url') ?: config('app.url'));
        $path = $action === 'deactivate' ? 'devices/deactivate' : "licence/{$action}";

        $this->line("<options=bold>Till</>     {$action} → {$url}/api/v1/{$path}");
        $this->line("<options=bold>Install</>  {$till->installId} ({$till->installCode})");

        try {
            $response = match ($action) {
                'activate' => $this->activate($till),
                'validate' => $till->validate((string) $this->option('licence'), $this->option('token') !== null ? (string) $this->option('token') : null),
                default => $till->deactivate((string) ($this->option('register') ?: $this->registerId())),
            };
        } catch (ConnectionException $e) {
            $this->error('Could not reach the portal: '.$e->getMessage());
            $this->line('The till changes nothing and keeps trading on its token; after 14 days without a check it locks.');

            return self::FAILURE;
        }

        if ($response === null) {
            return self::INVALID;
        }

        $this->printReply($response);

        if (! $response->successful()) {
            $this->warn('Till: shows "'.$response->json('message').'" and changes nothing ('.$response->json('code').').');

            return self::FAILURE;
        }

        if ($action === 'deactivate') {
            $this->info('Till: forgets its key and token and locks; backup and export still work.');

            return self::SUCCESS;
        }

        return $this->verdict($till, $response);
    }

    private function till(): SimulatedTill
    {
        $install = SimulatedTill::installFor((string) gethostname());
        $trusted = [];

        try {
            $trusted[] = app(KeyStore::class)->active()->kid;
        } catch (Throwable) {
            // No signing key here: the till's list is then empty and any certified kid is accepted.
        }

        return new SimulatedTill(
            (string) ($this->option('url') ?: config('app.url')),
            strtoupper((string) ($this->option('install') ?: $install['installId'])),
            strtoupper((string) ($this->option('install-code') ?: $install['installCode'])),
            (string) $this->option('name'),
            ['companyId' => $this->ulidFrom('company'), 'branchId' => $this->ulidFrom('branch'), 'registerId' => $this->registerId()],
            $trusted,
        );
    }

    private function activate(SimulatedTill $till): ?Response
    {
        $key = (string) $this->option('key');

        if ($key === '') {
            $this->error('activate needs --key=SSP-XXXX-XXXX-XXXX-XXXX.');

            return null;
        }

        $parsed = LicenceKey::tryParse($key);
        $this->line('<options=bold>Key</>      '.($parsed !== null ? LicenceKey::mask($parsed->last4()) : '(not an SSP key: the portal answers key.not_found)'));
        $this->newLine();

        return $till->activate($key);
    }

    private function printReply(Response $response): void
    {
        $this->newLine();
        $this->line("<options=bold>HTTP {$response->status()}</>  contract ".($response->header('X-SSPOS-Contract') ?: '-').'  trace '.($response->header('X-Trace-Id') ?: '-'));
        $json = $response->json();
        $this->line(is_array($json) ? (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response->body());
        $this->newLine();
    }

    private function verdict(SimulatedTill $till, Response $response): int
    {
        $status = (string) $response->json('status');
        $token = $response->json('licenceToken');
        $now = SimulatedTill::portalTime($response->json('portalTimeUtc'));
        $valid = true;

        if (is_string($token)) {
            $result = $till->verify($token, $now);
            $valid = $result['valid'];
            $this->line('<options=bold>Token check (as the till)</>');
            $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $check) => [$check['check'], $check['ok'] ? 'ok' : 'FAILED', $check['detail']], $result['checks']));
            $this->line('licenceId <options=bold>'.($result['payload']['licenceId'] ?? '?').'</> (use it with --licence to validate)');
        } else {
            $this->line('No new token: the till keeps the one it holds.');
        }

        $decision = SimulatedTill::decide($status, $valid);
        $this->newLine();

        match ($decision['decision']) {
            'trade' => $this->info('Till: TRADE. '.$decision['reason']),
            'trade with banner' => $this->warn('Till: TRADE WITH BANNER. '.$decision['reason']),
            'lock' => $this->error('Till: LOCK. '.$decision['reason']),
        };

        return $valid ? self::SUCCESS : self::FAILURE;
    }

    private function registerId(): string
    {
        return strtoupper((string) ($this->option('register') ?: $this->ulidFrom('register')));
    }

    private function ulidFrom(string $what): string
    {
        return SimulatedTill::installFor($what.'|'.gethostname())['installId'];
    }
}
