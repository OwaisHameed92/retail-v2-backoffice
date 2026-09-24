<?php

namespace App\Domain\Mail\Actions;

use App\Domain\Mail\Support\PasswordLinkMail;
use App\Models\User;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;

/**
 * Emails a new portal owner a branded "set your password" link (called by trial approval, module 1.6).
 * Uses the `user_setup` broker: a one-time token valid for 7 days (forgot-password links stay at 60 minutes).
 */
final class SendPasswordSetupLink
{
    public function handle(User $user, ?string $businessName = null, ?string $companyId = null): void
    {
        $token = Password::broker('user_setup')->createToken($user);

        Mail::queue(PasswordLinkMail::forUser($user, $token, firstTime: true, businessName: $businessName, companyId: $companyId));
    }
}
