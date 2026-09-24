<?php

namespace App\Domain\Admin\Models;

use App\Domain\Admin\Enums\AdminRole;
use Carbon\CarbonInterface;
use Database\Factories\AdminFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * An SSPOS staff member who signs in to the super admin area (/admin) with the "admin" guard.
 *
 * @property string $id
 * @property string $name
 * @property string $email
 * @property string $password
 * @property AdminRole $role
 * @property bool $is_active
 * @property CarbonInterface|null $last_login_at
 * @property string|null $remember_token
 * @property CarbonInterface|null $created_at
 * @property CarbonInterface|null $updated_at
 */
class Admin extends Authenticatable
{
    /** @use HasFactory<AdminFactory> */
    use HasFactory, HasUlids, Notifiable;

    /**
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'is_active',
    ];

    /**
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * @var array<string, mixed>
     */
    protected $attributes = [
        'is_active' => true,
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'role' => AdminRole::class,
            'is_active' => 'boolean',
            'last_login_at' => 'datetime',
        ];
    }

    protected static function newFactory(): AdminFactory
    {
        return AdminFactory::new();
    }

    public function hasAbility(string $ability): bool
    {
        return $this->is_active && $this->role->can($ability);
    }

    public function isOwner(): bool
    {
        return $this->role === AdminRole::Owner;
    }

    /**
     * @param  Builder<Admin>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('is_active', true);
    }

    /**
     * @param  Builder<Admin>  $query
     */
    public function scopeOwners(Builder $query): void
    {
        $query->where('role', AdminRole::Owner->value);
    }
}
