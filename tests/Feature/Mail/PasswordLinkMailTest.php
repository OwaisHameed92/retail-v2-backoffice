<?php

use App\Domain\Mail\Actions\SendPasswordSetupLink;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Domain\Mail\Models\EmailLog;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;

it('still sends the standard reset notification from forgot password', function () {
    Notification::fake();
    $user = User::factory()->create();

    $this->post('/forgot-password', ['email' => $user->email]);

    Notification::assertSentTo($user, ResetPassword::class);
});

it('uses the branded reset email for portal users', function () {
    $user = User::factory()->create(['name' => 'Aisha Khan', 'email' => 'aisha@example.test']);

    $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

    $messages = app('mailer')->getSymfonyTransport()->messages();
    expect($messages)->toHaveCount(1);

    $email = $messages->first()->getOriginalMessage();
    expect($email->getSubject())->toBe('Reset your Switch & Save password')
        ->and($email->getTo()[0]->getAddress())->toBe('aisha@example.test')
        ->and($email->getHtmlBody())->toContain('Hi Aisha')->toContain('/reset-password/')->toContain('switch-save-logo.png');

    $log = EmailLog::query()->sole();
    expect($log->template)->toBe('set-password')
        ->and($log->status->value)->toBe('sent')
        ->and($log->meta)->toBe(['purpose' => 'reset'])
        ->and(json_encode($log->toArray()))->not->toContain('reset-password/');
});

it('builds a working reset link in the branded email', function () {
    $user = User::factory()->create();
    $this->post('/forgot-password', ['email' => $user->email]);

    $html = app('mailer')->getSymfonyTransport()->messages()->first()->getOriginalMessage()->getHtmlBody();
    preg_match('#/reset-password/([A-Za-z0-9]+)\?email=#', $html, $match);

    expect($match)->toHaveKey(1)
        ->and(Password::broker()->tokenExists($user, $match[1]))->toBeTrue();
});

it('sends a first-time set-password link for new owners', function () {
    Mail::fake();
    $user = User::factory()->create(['email' => 'owner@example.test']);

    app(SendPasswordSetupLink::class)->handle($user, 'Khan Mini Mart');

    Mail::assertQueued(SetPasswordMail::class, function (SetPasswordMail $mail) use ($user) {
        return $mail->hasTo('owner@example.test')
            && $mail->data->firstTime
            && $mail->data->businessName === 'Khan Mini Mart'
            && $mail->subjectLine() === 'Set your Switch & Save password'
            && preg_match('#/reset-password/([A-Za-z0-9]+)\?#', $mail->data->url, $m) === 1
            && Password::broker('user_setup')->tokenExists($user, $m[1]);
    });
});
