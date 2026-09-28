<?php

namespace App\Domain\Licensing\Api\Simulator;

use App\Domain\Licensing\Api\Support\TillStatus;
use App\Domain\Licensing\Signing\Exceptions\LicenceTokenException;
use App\Domain\Licensing\Signing\Sspos\SsposTokenVerifier;
use App\Domain\Shared\Support\Ulid;
use App\Http\Middleware\EnsureTillContract;
use App\Http\Middleware\IdempotentTillRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use SensitiveParameter;
use Throwable;

/**
 * Behaves like the EPOS till against the per-till licence API (contract v1.3.1 §17.15) over HTTP, for
 * `php artisan licence:simulate`, demos and the EPOS team: sends the till's requests and headers, then checks a
 * returned `SSPOS1.` token the way the till does (signature, kid and signer certificate, source, installCode,
 * dates) and says what the till would do (§17.9).
 */
final class SimulatedTill
{
    public const APP_VERSION = '3.0.412';

    /**
     * @param  array{companyId: string, branchId: string, registerId: string}  $existingIds
     * @param  list<string>  $trustedKids
     */
    public function __construct(
        private readonly string $baseUrl,
        public readonly string $installId,
        public readonly string $installCode,
        private readonly string $deviceName,
        private readonly array $existingIds,
        private readonly array $trustedKids,
    ) {}

    /**
     * A stable fake install for this machine: a ULID-shaped id and a XXXX-XXXX code from a hash.
     *
     * @return array{installId: string, installCode: string}
     */
    public static function installFor(string $seed): array
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $hash = hash('sha256', 'sspos-simulator|'.$seed, true);
        $chars = '';

        foreach (str_split($hash) as $byte) {
            $chars .= $alphabet[ord($byte) & 31];
        }

        return ['installId' => '0'.substr($chars, 0, 25), 'installCode' => substr($chars, 24, 4).'-'.substr($chars, 28, 4)];
    }

    public function activate(#[SensitiveParameter] string $licenceKey): Response
    {
        return $this->post('licence/activate', [
            'licenceKey' => strtoupper((string) preg_replace('/\s+/', '', $licenceKey)),
            'installId' => $this->installId,
            'installCode' => $this->installCode,
            'deviceName' => $this->deviceName,
            'appVersion' => self::APP_VERSION,
            'os' => ['name' => PHP_OS_FAMILY.' (simulator)', 'version' => PHP_VERSION, 'architecture' => null],
            'tillClockUtc' => self::utc(CarbonImmutable::now()),
            'trustedKids' => $this->trustedKids,
            'existingIds' => $this->existingIds,
        ]);
    }

    public function validate(string $licenceId, ?string $token, bool $locked = false, ?string $lockReason = null): Response
    {
        $now = self::utc(CarbonImmutable::now());

        return $this->post('licence/validate', [
            'installId' => $this->installId,
            'installCode' => $this->installCode,
            'licenceId' => $licenceId,
            'tokenSha256' => hash('sha256', (string) $token),
            'deviceName' => $this->deviceName,
            'appVersion' => self::APP_VERSION,
            'os' => ['name' => PHP_OS_FAMILY.' (simulator)', 'version' => PHP_VERSION, 'architecture' => null],
            'trustedKids' => $this->trustedKids,
            'tillClockUtc' => $now,
            'clockWatermarkUtc' => $now,
            'lastValidatedAtUtc' => null,
            'lock' => ['locked' => $locked, 'reason' => $locked ? $lockReason : null],
        ]);
    }

    public function deactivate(string $registerId, string $reason = 'removed'): Response
    {
        return $this->post('devices/deactivate', [
            'registerId' => $registerId,
            'installId' => $this->installId,
            'reason' => $reason,
            'note' => 'Released by the licence simulator.',
        ]);
    }

    /**
     * Check a token like the till: format, v, known kid, signer certificate and signature (SsposTokenVerifier with
     * this portal's keys and approvers), then source portal, installCode = ours, validFrom ≤ now < expiresAt.
     *
     * @return array{valid: bool, checks: list<array{check: string, ok: bool, detail: string}>, payload: array<string, mixed>}
     */
    public function verify(string $token, CarbonImmutable $now): array
    {
        $checks = [];
        $add = function (string $check, bool $ok, string $detail) use (&$checks): bool {
            $checks[] = ['check' => $check, 'ok' => $ok, 'detail' => $detail];

            return $ok;
        };

        try {
            $verified = app(SsposTokenVerifier::class)->verify($token);
        } catch (LicenceTokenException $e) {
            $add('signature', false, class_basename($e).': '.$e->getMessage());

            return ['valid' => false, 'checks' => $checks, 'payload' => []];
        }

        $add('signature', true, 'Ed25519, kid '.$verified->kid().($verified->signerCertificate !== null ? ', signer certificate ok' : ', no signer certificate (dev key)'));
        $validFrom = $verified->date('validFrom');
        $expiresAt = $verified->date('expiresAt');

        $ok = $add('trusted kid', $this->trustedKids === [] || in_array($verified->kid(), $this->trustedKids, true) || $verified->signerCertificate !== null, 'kid '.$verified->kid())
            && $add('source', $verified->source() === 'portal', (string) json_encode($verified->source()))
            && $add('installCode', $verified->get('installCode') === $this->installCode, 'token '.json_encode($verified->get('installCode')).', this PC '.$this->installCode)
            && $add('dates', $validFrom !== null && $expiresAt !== null && $now->greaterThanOrEqualTo($validFrom) && $now->lessThan($expiresAt), ($validFrom?->format('Y-m-d H:i') ?? '?').' → '.($expiresAt?->format('Y-m-d H:i') ?? '?').' UTC');

        return ['valid' => $ok, 'checks' => $checks, 'payload' => $verified->payload];
    }

    /**
     * What the till does with a reply status (§17.9, §17.15.2).
     *
     * @return array{decision: 'trade'|'trade with banner'|'lock', reason: string}
     */
    public static function decide(string $status, bool $tokenValid): array
    {
        return match (true) {
            ! $tokenValid => ['decision' => 'lock', 'reason' => 'The token did not pass the till\'s checks.'],
            $status === TillStatus::ACTIVE => ['decision' => 'trade', 'reason' => 'Active licence.'],
            $status === TillStatus::EXPIRING => ['decision' => 'trade with banner', 'reason' => 'The licence ends soon: banner "Your licence ends on …".'],
            default => ['decision' => 'lock', 'reason' => "Status {$status}: withdraw the token and lock after the sale in progress."],
        };
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function post(string $path, array $body): Response
    {
        return $this->client()->post(rtrim($this->baseUrl, '/').'/api/v1/'.$path, $body);
    }

    private function client(): PendingRequest
    {
        return Http::acceptJson()->asJson()->timeout(30)->withHeaders([
            EnsureTillContract::CONTRACT_HEADER => EnsureTillContract::CONTRACT,
            EnsureTillContract::APP_VERSION_HEADER => self::APP_VERSION,
            EnsureTillContract::INSTALL_ID_HEADER => $this->installId,
            'X-SSPOS-Company-Id' => $this->existingIds['companyId'],
            'X-SSPOS-Branch-Id' => $this->existingIds['branchId'],
            'X-SSPOS-Register-Id' => $this->existingIds['registerId'],
            IdempotentTillRequest::HEADER => Ulid::new(),
            'User-Agent' => 'SSPOS-licence-simulator/2',
        ]);
    }

    private static function utc(CarbonImmutable $at): string
    {
        return $at->utc()->format('Y-m-d\TH:i:s\Z');
    }

    /** Parses a portal time for the verdict; the till trusts the portal's clock over its own. */
    public static function portalTime(mixed $value): CarbonImmutable
    {
        try {
            return is_string($value) && $value !== '' ? CarbonImmutable::parse($value, 'UTC')->utc() : CarbonImmutable::now('UTC');
        } catch (Throwable) {
            return CarbonImmutable::now('UTC');
        }
    }
}
