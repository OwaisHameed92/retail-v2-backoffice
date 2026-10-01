<?php

namespace App\Domain\Security\Contracts;

use Carbon\CarbonInterface;

/**
 * Someone who can sign in with a time-based one-time code: an Admin or a portal User (see HasTwoFactor).
 *
 * @property string|null $two_factor_secret Decrypted base32 secret (encrypted at rest).
 * @property list<string>|null $two_factor_recovery_codes Keyed hashes of the unused recovery codes.
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 * @property string $name
 * @property string $email
 */
interface TwoFactorUser
{
    /** @return mixed */
    public function getKey();

    public function hasTwoFactorEnabled(): bool;

    /** Changes whenever the secret changes: binds "passed" sessions and remembered devices to this secret. */
    public function twoFactorStamp(): string;

    public function remainingRecoveryCodes(): int;

    /** The email shown in the authenticator app. */
    public function twoFactorAccountName(): string;

    /** The decrypted base32 secret, or null when two-factor is off. */
    public function twoFactorSecret(): ?string;

    /** The last accepted time step (a code is never accepted twice). */
    public function twoFactorLastStep(): ?int;

    /** Turns two-factor off for this account. Saves. */
    public function clearTwoFactor(): void;
}
