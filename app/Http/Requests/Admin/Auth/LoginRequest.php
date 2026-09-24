<?php

namespace App\Http\Requests\Admin\Auth;

use App\Domain\Admin\Models\Admin;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class LoginRequest extends FormRequest
{
    public const MAX_ATTEMPTS = 5;

    public const DECAY_SECONDS = 60;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Sign in with the "admin" guard. Inactive admins are rejected with the same message as a wrong
     * password so the form does not reveal which accounts exist.
     *
     * @throws ValidationException
     */
    public function authenticate(): Admin
    {
        $this->ensureIsNotRateLimited();

        $credentials = [
            'email' => Str::lower($this->string('email')->trim()->value()),
            'password' => $this->string('password')->value(),
            'is_active' => true,
        ];

        $guard = Auth::guard('admin');

        if (! $guard->attempt($credentials, $this->boolean('remember'))) {
            RateLimiter::hit($this->throttleKey(), self::DECAY_SECONDS);

            throw ValidationException::withMessages(['email' => __('auth.failed')]);
        }

        RateLimiter::clear($this->throttleKey());

        /** @var Admin $admin */
        $admin = $guard->user();

        return $admin;
    }

    /**
     * @throws ValidationException
     */
    public function ensureIsNotRateLimited(): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), self::MAX_ATTEMPTS)) {
            return;
        }

        event(new Lockout($this));

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('auth.throttle', ['seconds' => $seconds, 'minutes' => ceil($seconds / 60)]),
        ]);
    }

    public function throttleKey(): string
    {
        return 'admin-login|'.Str::transliterate(Str::lower($this->string('email')->trim()->value()).'|'.$this->ip());
    }
}
