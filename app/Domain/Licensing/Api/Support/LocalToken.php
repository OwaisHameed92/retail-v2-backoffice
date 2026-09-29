<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Signing\Exceptions\LicenceTokenException;
use App\Domain\Licensing\Signing\Sspos\LicenceClaims;
use App\Domain\Licensing\Signing\Sspos\SsposCodec;
use App\Domain\Licensing\Signing\Sspos\SsposTokenVerifier;
use App\Domain\Licensing\Signing\Sspos\VerifiedSsposToken;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\Ulid;
use Carbon\CarbonImmutable;
use Throwable;

/**
 * A licence token a till hands us in `licence/redeem` or `cloud/migrate` (contract v1.4.1 §17.2 "How a till (and
 * your redeem/migrate code) verifies a token", §17.6, §17.8): steps 1–4 only (format, version, known kid or signer
 * certificate, signature) with our keys and the owner's generator keys (config licence.trusted_keys). Dates and
 * binding are the caller's rule. Refusals are 422 with the till's own names (RedeemErrors::token).
 */
final class LocalToken
{
    public function __construct(private readonly SsposTokenVerifier $verifier) {}

    /**
     * @throws ApiException 422 licence.*
     */
    public function verify(string $token): VerifiedSsposToken
    {
        try {
            $verified = $this->verifier->verify($token);
        } catch (LicenceTokenException $e) {
            throw RedeemErrors::token($e, self::kidOf($token));
        }

        // What a record needs: a licence id and an end date (every token has one, §17.2).
        try {
            if (! Ulid::isValid((string) $verified->licenceId()) || $verified->date('expiresAt') === null) {
                throw RedeemErrors::format();
            }

            $verified->date('validFrom');
            $verified->date('issuedAt');
        } catch (ApiException $e) {
            throw $e;
        } catch (Throwable) {
            throw RedeemErrors::format();
        }

        return $verified;
    }

    /** What the token means today, in the contract's words (active / expiring / expired). */
    public static function status(VerifiedSsposToken $token, CarbonImmutable $now): string
    {
        $expiresAt = $token->date('expiresAt') ?? $now;

        return match (true) {
            $now->greaterThan($expiresAt) => TillStatus::EXPIRED,
            $now->addDays(max(0, (int) config('licence.api.expiring_days', 7)))->greaterThanOrEqualTo($expiresAt) => TillStatus::EXPIRING,
            default => TillStatus::ACTIVE,
        };
    }

    /**
     * The reply's plain `licence` summary of a local token (common.schema.json licenceSummary). An open key's
     * `installCode` is the install it is now bound to.
     *
     * @return array<string, mixed>
     */
    public static function summary(VerifiedSsposToken $token, string $boundInstallCode): array
    {
        $installCode = self::text($token->get('installCode'));

        return [
            'licenceId' => $token->licenceId(),
            'kind' => $token->kind()->value ?? 'full',
            'source' => 'local',
            'companyId' => self::text($token->get('companyId')) ?? '',
            'branchId' => self::text($token->get('branchId')) ?? '',
            'businessName' => self::limit($token->get('businessName'), 100),
            'branchName' => self::limit($token->get('branchName'), 100),
            'installCode' => $installCode ?? $boundInstallCode,
            'validFrom' => self::utc($token->date('validFrom')),
            'expiresAt' => self::utc($token->date('expiresAt')),
            'maxRegisters' => self::maxRegisters($token),
            'features' => self::features($token),
            'limits' => self::limits($token),
            'company' => is_array($token->get('company')) && $token->get('company') !== [] ? $token->get('company') : null,
        ];
    }

    public static function maxRegisters(VerifiedSsposToken $token): int
    {
        $value = $token->get('maxRegisters');

        return is_int($value) && $value >= 1 ? min($value, 999) : 1;
    }

    /**
     * @return list<string>|null
     */
    public static function features(VerifiedSsposToken $token): ?array
    {
        $features = array_values(array_filter((array) $token->get('features', []), fn ($f) => is_string($f) && preg_match(LicenceClaims::FEATURE, $f) === 1));

        return $features === [] ? null : $features;
    }

    /**
     * @return array<string, int>|null
     */
    public static function limits(VerifiedSsposToken $token): ?array
    {
        $limits = array_filter((array) $token->get('limits', []), fn ($v, $k) => is_string($k) && is_int($v), ARRAY_FILTER_USE_BOTH);

        return $limits === [] ? null : $limits;
    }

    public static function text(mixed $value): ?string
    {
        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }

    private static function limit(mixed $value, int $length): ?string
    {
        $text = self::text($value);

        return $text === null ? null : mb_substr($text, 0, $length);
    }

    private static function utc(?CarbonImmutable $at): ?string
    {
        return $at === null ? null : LicenceClaims::utc($at);
    }

    /** The kid a refused token names, for `licence.unknown_key` details (never trusted for anything else). */
    private static function kidOf(string $token): ?string
    {
        try {
            $kid = SsposCodec::parse(SsposCodec::TOKEN_PREFIX, $token)['payload']['kid'] ?? null;
        } catch (Throwable) {
            return null;
        }

        return is_string($kid) && preg_match('/^[A-Za-z0-9._-]{1,64}$/', $kid) === 1 ? $kid : null;
    }
}
