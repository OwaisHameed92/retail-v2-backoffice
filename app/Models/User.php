<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Domain\Mail\Support\PasswordLinkMail;
use App\Domain\Security\Concerns\HasTwoFactor;
use App\Domain\Security\Contracts\TwoFactorUser;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\CompanyMembership;
use Carbon\CarbonInterface;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use SensitiveParameter;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $two_factor_secret
 * @property list<string>|null $two_factor_recovery_codes
 * @property CarbonInterface|null $two_factor_confirmed_at
 * @property int|null $two_factor_last_step
 */
class User extends Authenticatable implements TwoFactorUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasTwoFactor, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    /**
     * Companies this user belongs to (active and inactive memberships).
     *
     * @return BelongsToMany<Company, $this, CompanyMembership, 'membership'>
     */
    public function companies(): BelongsToMany
    {
        return $this->belongsToMany(Company::class)
            ->using(CompanyMembership::class)
            ->as('membership')
            ->withPivot(['role', 'is_active', 'branch_id'])
            ->withTimestamps();
    }

    /**
     * Forgot-password email, branded (module 1.7). First-time "set your password" links are sent by
     * App\Domain\Mail\Actions\SendPasswordSetupLink instead.
     *
     * @param  string  $token
     */
    public function sendPasswordResetNotification(#[SensitiveParameter] $token): void
    {
        $this->notify(PasswordLinkMail::resetNotification($token));
    }
}
