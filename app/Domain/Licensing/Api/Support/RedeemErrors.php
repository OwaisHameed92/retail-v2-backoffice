<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LocalLicenceKey;
use App\Domain\Licensing\Signing\Exceptions\BadSignerCertificate;
use App\Domain\Licensing\Signing\Exceptions\InvalidSignature;
use App\Domain\Licensing\Signing\Exceptions\LicenceTokenException;
use App\Domain\Licensing\Signing\Exceptions\UnknownKey;
use App\Domain\Licensing\Signing\Exceptions\UnsupportedVersion;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;

/**
 * `licence/redeem` and `cloud/migrate` refusals of a key or a local token (contract v1.4.1 §17.6, §17.12,
 * error-codes.json). Messages are en-GB, for the shop owner; they never contain a key or a token.
 */
final class RedeemErrors
{
    private const DEALER = 'Please ask your dealer for help.';

    public static function keyAlreadyRedeemed(Licence $licence): ApiException
    {
        $name = $licence->device_name ?? 'another PC';

        return new ApiException('key.already_redeemed', "This licence key has already been used on {$name}. Ask your dealer to release it first.", 409, null, null, [
            'redeemedAtUtc' => ApiDate::format($licence->bound_at ?? $licence->activated_at),
        ]);
    }

    public static function keyNotForThisBranch(): ApiException
    {
        return new ApiException('key.not_for_this_branch', 'This licence key was issued for another shop. Enter the key from the e-mail for this shop. '.self::DEALER, 403);
    }

    public static function keyNotAllowed(string $message): ApiException
    {
        return new ApiException('key.not_allowed', $message, 403);
    }

    public static function keyUsedOnAnotherInstall(LocalLicenceKey $key): ApiException
    {
        return new ApiException('key.used_on_another_install', 'This key is already used on another PC. Contact your dealer for a key for this PC.', 409, null, null, [
            'licenceId' => $key->licence_id,
            'installCode' => $key->install_code,
            'firstSeenUtc' => ApiDate::format($key->first_seen_at),
        ]);
    }

    /** A token that fails §17.2 steps 1–4 → 422 with the till's own name for it. */
    public static function token(LicenceTokenException $e, ?string $kid = null): ApiException
    {
        return match (true) {
            $e instanceof UnsupportedVersion => new ApiException('licence.unsupported_version', 'This licence key needs a newer version of SSPOS. Update the till, then try again.', 422),
            $e instanceof UnknownKey => new ApiException('licence.unknown_key', 'This licence key was made by a key generator the portal does not know. '.self::DEALER, 422, null, null, ['kid' => $kid]),
            $e instanceof BadSignerCertificate => new ApiException('licence.bad_signer_certificate', 'This licence key is not genuine. '.self::DEALER, 422),
            $e instanceof InvalidSignature => new ApiException('licence.bad_signature', 'This licence key is not genuine or has been changed. Paste it again or ask your dealer.', 422),
            default => self::format(),
        };
    }

    public static function format(): ApiException
    {
        return new ApiException('licence.format', 'This licence key is damaged or incomplete. Paste it again or ask your dealer.', 422);
    }

    public static function expired(): ApiException
    {
        return new ApiException('licence.expired', 'This licence key has expired. Ask your dealer for a new key.', 422);
    }

    public static function wrongShop(): ApiException
    {
        return new ApiException('licence.wrong_shop', 'This licence key was made for another business. '.self::DEALER, 422);
    }

    public static function wrongBranch(): ApiException
    {
        return new ApiException('licence.wrong_branch', 'This licence key was made for another of your shops. '.self::DEALER, 422);
    }
}
