<?php

namespace App\Domain\Mail\Support;

use App\Domain\Mail\Data\SetPasswordData;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Contracts\Auth\CanResetPassword;
use Illuminate\Notifications\Messages\MailMessage;
use SensitiveParameter;

/**
 * Builds the branded password email for portal users.
 *
 * The forgot-password flow still sends Laravel's ResetPassword notification (so the broker, throttling and tests
 * stay standard); its mail body is swapped for SetPasswordMail through ResetPassword::toMailUsing(), registered
 * from User::sendPasswordResetNotification(). Other notifiables keep a plain reset email.
 */
final class PasswordLinkMail
{
    /** The notification User::sendPasswordResetNotification() sends, with the branded body registered. */
    public static function resetNotification(#[SensitiveParameter] string $token): ResetPassword
    {
        ResetPassword::toMailUsing(static function (mixed $notifiable, string $token): SetPasswordMail|MailMessage {
            if ($notifiable instanceof User) {
                return self::forUser($notifiable, $token, firstTime: false);
            }

            return self::plainReset($notifiable, $token);
        });

        return new ResetPassword($token);
    }

    public static function forUser(
        User $user,
        #[SensitiveParameter] string $token,
        bool $firstTime,
        ?string $businessName = null,
        ?string $companyId = null,
    ): SetPasswordMail {
        $mail = new SetPasswordMail(new SetPasswordData(
            name: $user->name,
            email: $user->email,
            url: self::url($user, $token, $firstTime),
            expiresInMinutes: self::expiresInMinutes($firstTime ? 'user_setup' : null),
            firstTime: $firstTime,
            businessName: $businessName,
            companyId: $companyId,
        ));

        return $mail->to($user->email, $user->name);
    }

    private static function url(CanResetPassword $user, #[SensitiveParameter] string $token, bool $setup = false): string
    {
        return url(route('password.reset', ['token' => $token, 'email' => $user->getEmailForPasswordReset()] + ($setup ? ['setup' => 1] : []), false));
    }

    private static function expiresInMinutes(?string $broker = null): int
    {
        $broker ??= (string) config('auth.defaults.passwords', 'users');

        return (int) config("auth.passwords.{$broker}.expire", 60);
    }

    private static function plainReset(mixed $notifiable, #[SensitiveParameter] string $token): MailMessage
    {
        $email = $notifiable instanceof CanResetPassword ? $notifiable->getEmailForPasswordReset() : '';
        $url = url(route('password.reset', ['token' => $token, 'email' => $email], false));

        return (new MailMessage)
            ->subject('Reset your password')
            ->line('We received a request to reset the password for your account.')
            ->action('Reset your password', $url)
            ->line('This link expires in '.self::expiresInMinutes().' minutes.')
            ->line('If you did not ask to reset your password, you can ignore this email.');
    }
}
