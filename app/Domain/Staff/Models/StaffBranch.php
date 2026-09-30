<?php

namespace App\Domain\Staff\Models;

use App\Domain\Shared\Support\Ulid;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;

/**
 * A shop a till staff member works at (module 4.5). Portal-only: the till's `User` has no shop, so every till still
 * receives every staff member. Table `till_user_branches`.
 *
 * @property string $id
 * @property string $company_id
 * @property string $till_user_id
 * @property string $branch_id
 */
class StaffBranch extends Model
{
    use BelongsToCompany, HasUlids;

    protected $table = 'till_user_branches';

    protected $guarded = [];

    public function newUniqueId(): string
    {
        return Ulid::new();
    }
}
