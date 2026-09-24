<?php

namespace App\Console\Commands;

use App\Domain\Licensing\Api\Simulator\SimulatedTill;
use App\Domain\Licensing\LicenceKey;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;

/**
 * A pretend till for demos and the EPOS team (module 1.5). Calls the real licence API over HTTP, prints the
 * reply, verifies the token with the public JWKS like a till would and says what the till would do.
 *
 *     php artisan licence:simulate SSP-7K2Q-9DMF-3XRA-P8T5 --action=activate --device=DEMO-PC-1
 *     php artisan licence:simulate SSP-7K2Q-9DMF-3XRA-P8T5 --action=check-in --device=DEMO-PC-1 --url=https://portal.test
 *
 * The key is only printed masked (SSP-••••-••••-••••-P8T5).
 */
class LicenceSimulateCommand extends Command
{
    protected $signature = 'licence:simulate
        {key : The licence key, as typed on the till}
        {--action=activate : activate, check-in or deactivate}
        {--device= : The PC\'s device id (default: a fixed id for this machine)}
        {--name=SIMULATED-TILL : The PC name sent on activate}
        {--url= : Portal base URL (default: APP_URL)}';

    protected $description = 'Act as a till against the licence API: call it, verify the token with the JWKS, show what the till would do';

    private const ACTIONS = ['activate', 'check-in', 'deactivate'];

    public function handle(): int
    {
        $action = (string) $this->option('action');

        if (! in_array($action, self::ACTIONS, true)) {
            $this->error('--action must be one of: '.implode(', ', self::ACTIONS).'.');

            return self::INVALID;
        }

        $key = (string) $this->argument('key');
        $parsed = LicenceKey::tryParse($key);
        $url = (string) ($this->option('url') ?: config('app.url'));
        $device = (string) ($this->option('device') ?: 'SIM-'.strtoupper(substr(hash('sha256', (string) gethostname()), 0, 12)));
        $till = new SimulatedTill($url, $device, (string) $this->option('name'));

        $this->line("<options=bold>Till</>  {$action} → {$url}/api/v1/licence/{$action}");
        $this->line('<options=bold>Key</>   '.($parsed !== null ? LicenceKey::mask($parsed->last4()) : '(not a valid SSP key: the real till would say "check the key" without calling us)'));
        $this->line("<options=bold>PC</>    {$device}");
        $this->newLine();

        try {
            $response = $till->call($action, $key);
        } catch (ConnectionException $e) {
            $this->error('Could not reach the portal: '.$e->getMessage());
            $this->line('The till would keep trading on its last token until validUntil.');

            return self::FAILURE;
        }

        $this->printReply($response);

        if (! $response->successful()) {
            $this->explainError($response);

            return self::FAILURE;
        }

        if ($action === 'deactivate') {
            $this->info('The till would delete its stored token and licence details.');

            return self::SUCCESS;
        }

        return $this->verifyToken($till, $response) ? self::SUCCESS : self::FAILURE;
    }

    private function printReply(Response $response): void
    {
        $this->line("<options=bold>HTTP {$response->status()}</>  trace ".($response->header('X-Trace-Id') ?: '-'));
        $json = $response->json();
        $this->line(is_array($json) ? (string) json_encode($json, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : $response->body());
        $this->newLine();
    }

    private function verifyToken(SimulatedTill $till, Response $response): bool
    {
        $token = $response->json('token');
        $serverTime = is_string($response->json('serverTimeUtc')) ? CarbonImmutable::parse((string) $response->json('serverTimeUtc'), 'UTC') : null;
        // Clock rule: when online, trust the portal's time over the PC clock.
        $now = $serverTime ?? CarbonImmutable::now('UTC');

        if (! is_string($token)) {
            $this->error('The reply has no token.');

            return false;
        }

        $keys = $till->publicKeys();
        $result = $till->verify($token, $keys, $now);

        $this->line('<options=bold>Token check (public JWKS only, '.count($keys).' '.(count($keys) === 1 ? 'key' : 'keys').')</>');
        $this->table(['Check', 'Result', 'Detail'], array_map(fn (array $check) => [
            $check['check'], $check['ok'] ? 'ok' : 'FAILED', $check['detail'],
        ], $result['checks']));

        if ($result['claims'] !== []) {
            $claims = $result['claims'];
            $this->line(sprintf(
                'status <options=bold>%s</> · plan %s · expiresAt %s · graceDays %s · validUntil %s',
                $claims['status'] ?? '?', $claims['plan'] ?? '-', $claims['expiresAt'] ?? '-', $claims['graceDays'] ?? '-', $claims['validUntil'] ?? '-',
            ));
        }

        $message = $response->json('message');
        $decision = SimulatedTill::decide($result['claims'], $result['valid'], $now, is_string($message) ? $message : null);
        $this->newLine();

        match ($decision['decision']) {
            'trade' => $this->info('Till: TRADE. '.$decision['reason']),
            'grace banner' => $this->warn('Till: TRADE WITH GRACE BANNER. '.$decision['reason']),
            'lock' => $this->error('Till: LOCK. '.trim($decision['reason'])),
        };

        return $result['valid'];
    }

    private function explainError(Response $response): void
    {
        $code = (string) $response->json('code');
        $retry = $response->json('retryAfterSeconds');

        $this->warn('Till: '.match ($code) {
            'licence.not_found' => 'show "We could not find this licence key. Check it and try again."',
            'licence.bound_to_other_device' => 'show "This key is in use on another PC" and ask the owner to contact support (staff can reset the PC).',
            'licence.device_mismatch' => 'keep trading on the last token until its validUntil, then lock; tell staff to contact support.',
            'licence.revoked' => 'lock and delete the stored licence: the key never works again.',
            'licence.not_activatable' => 'stay unactivated and show the message (suspended or expired).',
            'contract.unsupported' => 'ask the owner to update SSPOS.',
            'rate_limited' => 'wait '.(is_int($retry) ? $retry : '?').' seconds, then try again.',
            default => 'show the message and try again later.',
        });
    }
}
