<?php

namespace App\Domain\Security\Actions;

use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Security\Enums\TwoFactorArea;
use App\Domain\Security\Support\Totp;
use App\Domain\Security\Support\TwoFactorSession;
use Illuminate\Contracts\Session\Session;
use Illuminate\Validation\ValidationException;

/**
 * Starts (or resumes) setting up an authenticator app: a new secret is kept, encrypted, in the session until the
 * first code confirms it (ConfirmTwoFactorSetup). Reloading the page shows the same QR code. Refused when two-factor
 * is already on, so a stolen password alone can never replace the secret.
 */
final class BeginTwoFactorSetup
{
    public function __construct(private readonly Totp $totp) {}

    /**
     * @return array{qrSvg: string, secret: string, otpauthUrl: string}
     *
     * @throws ValidationException
     */
    public function handle(Session $session, TwoFactorArea $area, TwoFactorUser $user): array
    {
        if ($user->hasTwoFactorEnabled()) {
            throw ValidationException::withMessages(['code' => 'Two-factor sign-in is already on for this account.']);
        }

        $secret = TwoFactorSession::pendingSecret($session, $area, $user);

        if ($secret === null) {
            $secret = $this->totp->newSecret();
            TwoFactorSession::putPendingSecret($session, $area, $user, $secret);
        }

        $url = $this->totp->otpauthUrl($area->issuer(), $user->twoFactorAccountName(), $secret);

        return [
            'qrSvg' => $this->totp->qrSvg($url),
            'secret' => Totp::formatSecret($secret),
            'otpauthUrl' => $url,
        ];
    }
}
