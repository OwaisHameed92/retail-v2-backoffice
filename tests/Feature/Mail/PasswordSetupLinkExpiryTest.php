<?php

use App\Domain\Mail\Actions\SendPasswordSetupLink;
use App\Domain\Mail\Mailables\SetPasswordMail;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

function setupTokenFor(User $user): string
{
    return Password::broker('user_setup')->createToken($user);
}

function submitNewPassword(User $user, string $token): TestResponse
{
    return test()->post(route('password.store'), [
        'token' => $token,
        'email' => $user->email,
        'password' => 'a-new-password-123',
        'password_confirmation' => 'a-new-password-123',
    ]);
}

it('emails a setup link that says it lasts 7 days and opens the set-password page', function () {
    Mail::fake();
    $user = User::factory()->create();

    app(SendPasswordSetupLink::class)->handle($user, 'Khan Mini Mart');

    Mail::assertQueued(SetPasswordMail::class, function (SetPasswordMail $mail) {
        $html = $mail->render();

        return str_contains($html, '7 days') && str_contains($html, 'setup=1');
    });
});

it('accepts a setup token 6 days later', function () {
    $user = User::factory()->create();
    $token = setupTokenFor($user);

    $this->travel(6)->days();

    submitNewPassword($user, $token)->assertRedirect(route('login'));
    expect(Hash::check('a-new-password-123', $user->fresh()->password))->toBeTrue();
});

it('rejects a setup token after 7 days', function () {
    $user = User::factory()->create();
    $token = setupTokenFor($user);

    $this->travel(8)->days();

    submitNewPassword($user, $token)->assertSessionHasErrors('email');
});

it('keeps forgot-password links at 60 minutes', function () {
    $user = User::factory()->create();
    $token = Password::broker('users')->createToken($user);

    $this->travel(61)->minutes();

    submitNewPassword($user, $token)->assertSessionHasErrors('email');
});

it('shows the set-password wording on the setup page', function () {
    $this->get(route('password.reset', ['token' => 'abc', 'email' => 'a@example.com', 'setup' => 1]))
        ->assertInertia(fn ($page) => $page->component('auth/reset-password')->where('setup', true));
});
