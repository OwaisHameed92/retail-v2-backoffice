<?php

namespace App\Domain\Licensing\Api\Simulator;

use App\Domain\Licensing\Signing\Base64Url;
use App\Domain\Licensing\Signing\Ed25519Jws;
use App\Domain\Licensing\Signing\Exceptions\MalformedToken;
use App\Http\Middleware\EnsureLicenceContract;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * Behaves like the EPOS till against the licence API over HTTP (for `php artisan licence:simulate`, demos and
 * the EPOS team): sends the real requests, then checks the token only with the public JWKS, exactly as
 * docs/specs/licence-token-verification.md tells the till to, and says what the till would do.
 */
final class SimulatedTill
{
    public const APP_VERSION = '1.0.0-simulator';

    public const ISSUER = 'sspos-portal';

    public const TOKEN_TYPE = 'sspos-licence+jwt';

    /** The till warns staff this many days before validUntil. */
    public const WARN_DAYS = 3;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $deviceId,
        private readonly string $deviceName,
    ) {}

    /**
     * POST activate / check-in / deactivate with the body the till would send.
     */
    public function call(string $action, #[\SensitiveParameter] string $licenceKey, ?string $tokenId = null): Response
    {
        $now = CarbonImmutable::now('UTC')->format('Y-m-d\TH:i:s\Z');

        $body = match ($action) {
            'activate' => [
                'licenceKey' => $licenceKey,
                'deviceId' => $this->deviceId,
                'deviceName' => $this->deviceName,
                'appVersion' => self::APP_VERSION,
                'os' => PHP_OS_FAMILY.' (simulator)',
                'requestedAt' => $now,
            ],
            'check-in' => [
                'licenceKey' => $licenceKey,
                'deviceId' => $this->deviceId,
                'appVersion' => self::APP_VERSION,
                'tokenId' => $tokenId,
                'lastSaleAt' => null,
                'requestedAt' => $now,
            ],
            default => ['licenceKey' => $licenceKey, 'deviceId' => $this->deviceId],
        };

        return $this->client()->post($this->url($action), $body);
    }

    /**
     * The public keys from GET /keys, as kid → raw 32-byte Ed25519 public key.
     *
     * @return array<string, string>
     */
    public function publicKeys(): array
    {
        $keys = [];

        foreach ((array) $this->client()->get($this->url('keys'))->json('keys', []) as $jwk) {
            if (is_array($jwk) && ($jwk['kty'] ?? null) === 'OKP' && ($jwk['crv'] ?? null) === 'Ed25519' && is_string($jwk['kid'] ?? null) && is_string($jwk['x'] ?? null)) {
                try {
                    $keys[$jwk['kid']] = Base64Url::decode($jwk['x']);
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return $keys;
    }

    /**
     * Verify a token like the till: structure, alg/typ/crit, known kid, signature, iss, deviceId, validUntil.
     *
     * @param  array<string, string>  $publicKeys
     * @return array{valid: bool, checks: list<array{check: string, ok: bool, detail: string}>, claims: array<string, mixed>}
     */
    public function verify(string $token, array $publicKeys, CarbonImmutable $now): array
    {
        $checks = [];
        $add = function (string $check, bool $ok, string $detail) use (&$checks): bool {
            $checks[] = ['check' => $check, 'ok' => $ok, 'detail' => $detail];

            return $ok;
        };

        try {
            $jws = Ed25519Jws::parse($token);
        } catch (MalformedToken $e) {
            $add('format', false, $e->getMessage());

            return ['valid' => false, 'checks' => $checks, 'claims' => []];
        }

        $header = $jws['header'];
        $kid = is_string($header['kid'] ?? null) ? $header['kid'] : '';
        $ok = $add('header', ($header['alg'] ?? null) === 'EdDSA' && ($header['typ'] ?? null) === self::TOKEN_TYPE && ! array_key_exists('crit', $header), 'alg EdDSA, typ '.self::TOKEN_TYPE.', no crit')
            && $add('kid', isset($publicKeys[$kid]), $kid === '' ? 'missing' : "{$kid} ".(isset($publicKeys[$kid]) ? 'is in the JWKS' : 'is not in the JWKS'))
            && $add('signature', Ed25519Jws::verify($jws['signingInput'], $jws['signature'], $publicKeys[$kid]), 'Ed25519 over header.payload');

        if (! $ok) {
            return ['valid' => false, 'checks' => $checks, 'claims' => []];
        }

        $claims = Ed25519Jws::decodeJsonObject($jws['payload'], 'payload');
        $validUntil = self::date($claims['validUntil'] ?? null);

        $ok = $add('iss', ($claims['iss'] ?? null) === self::ISSUER, (string) json_encode($claims['iss'] ?? null))
            && $add('deviceId', ($claims['deviceId'] ?? null) === $this->deviceId, 'token is for '.json_encode($claims['deviceId'] ?? null).', this PC is "'.$this->deviceId.'"')
            && $add('validUntil', $validUntil !== null && $now->lessThan($validUntil), $validUntil === null ? 'missing' : $validUntil->format('Y-m-d H:i:s').' UTC');

        return ['valid' => $ok, 'checks' => $checks, 'claims' => $claims];
    }

    /**
     * What the till does with a verified token: trade, trade with a banner, or lock.
     *
     * @param  array<string, mixed>  $claims
     * @return array{decision: 'trade'|'grace banner'|'lock', reason: string}
     */
    public static function decide(array $claims, bool $valid, CarbonImmutable $now, ?string $message = null): array
    {
        if (! $valid) {
            return ['decision' => 'lock', 'reason' => 'The token did not verify: block sales until a good check-in.'];
        }

        $status = (string) ($claims['status'] ?? '');
        $validUntil = self::date($claims['validUntil'] ?? null);
        $warn = $validUntil !== null && $now->diffInSeconds($validUntil) < self::WARN_DAYS * 86400
            ? ' Warn staff: the licence must be renewed online by '.$validUntil->format('Y-m-d H:i').' UTC.'
            : '';

        return match ($status) {
            'trial', 'active' => ['decision' => 'trade', 'reason' => ucfirst($status).' licence.'.$warn],
            'grace' => ['decision' => 'grace banner', 'reason' => 'Trade, with a banner: '.($message ?? 'the licence has ended and is in its grace days.').$warn],
            default => ['decision' => 'lock', 'reason' => "Status {$status}: block sales. ".($message ?? '')],
        };
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(20)->withHeaders([
            EnsureLicenceContract::HEADER => EnsureLicenceContract::VERSION,
            EnsureLicenceContract::APP_VERSION_HEADER => self::APP_VERSION,
            'User-Agent' => 'SSPOS-licence-simulator/1',
        ]);
    }

    private function url(string $action): string
    {
        return rtrim($this->baseUrl, '/').'/api/v1/licence/'.$action;
    }

    private static function date(mixed $value): ?CarbonImmutable
    {
        if (! is_string($value) || $value === '') {
            return null;
        }

        try {
            return CarbonImmutable::parse($value, 'UTC')->utc();
        } catch (Throwable) {
            return null;
        }
    }
}
