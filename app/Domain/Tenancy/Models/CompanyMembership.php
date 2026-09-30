<?php

namespace App\Domain\Tenancy\Models;

use App\Domain\Tenancy\Enums\CompanyRole;
use Illuminate\Database\Eloquent\Relations\Pivot;

/**
 * A user's membership of a company (the company_user pivot row).
 *
 * @property int $id
 * @property string $company_id
 * @property int $user_id
 * @property CompanyRole $role
 * @property string|null $branch_id one shop only (module 3.3; null = every shop)
 * @property bool $is_active
 */
class CompanyMembership extends Pivot
{
    protected $table = 'company_user';

    public $incrementing = true;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => CompanyRole::class,
            'is_active' => 'boolean',
        ];
    }
}
