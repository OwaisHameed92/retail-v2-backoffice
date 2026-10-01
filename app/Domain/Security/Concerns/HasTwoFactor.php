<?php

namespace App\Domain\Security\Concerns;

use App\Domain\Security\Contracts\TwoFactorUser;

/**
 * Two-factor columns for an authenticatable model ({@see TwoFactorUser}). The secret is encrypted at rest; the
 * recovery codes are keyed hashes (RecoveryCodes). None of them is ever serialised.
 */
trait HasTwoFactor
{
    public function initializeHasTwoFactor(): void
    {
        $this->mergeCasts([
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'array',
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_last_step' => 'integer',
        ]);

        $this->makeHidden(['two_factor_secret', 'two_factor_recovery_codes', 'two_factor_last_step']);
    }

    public function hasTwoFactorEnabled(): bool
    {
        return $this->two_factor_confirmed_at !== null && $this->getRawOriginal('two_factor_secret') !== null;
    }

    public function twoFactorStamp(): string
    {
        $raw = $this->getRawOriginal('two_factor_secret');

        return is_string($raw) && $this->two_factor_confirmed_at !== null ? hash('sha256', $raw) : 'none';
    }

    public function remainingRecoveryCodes(): int
    {
        return count($this->two_factor_recovery_codes ?? []);
    }

    public function twoFactorAccountName(): string
    {
        return (string) $this->getAttribute('email');
    }

    public function twoFactorSecret(): ?string
    {
        $secret = $this->two_factor_secret;

        return is_string($secret) && $secret !== '' ? $secret : null;
    }

    public function twoFactorLastStep(): ?int
    {
        return $this->two_factor_last_step;
    }

    /** Turns two-factor off for this account (secret, codes and replay guard). Saves. */
    public function clearTwoFactor(): void
    {
        $this->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
            'two_factor_last_step' => null,
        ])->save();
    }
}
