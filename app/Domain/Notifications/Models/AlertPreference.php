<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Notifications\Enums\AlertType;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Illuminate\Database\Eloquent\Model;

/**
 * One portal user's alert choices in one business (module 7.8). A missing row or type means the role's default
 * ({@see AlertType::defaultFor()}). Tenant-owned; the alert jobs read it with `withoutCompanyScope()` for a known
 * company.
 *
 * @property string $id
 * @property string $company_id
 * @property int $user_id
 * @property array<string, string>|null $deliveries
 * @property list<string>|null $branch_ids
 */
class AlertPreference extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'deliveries', 'branch_ids'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'deliveries' => 'array', 'branch_ids' => 'array'];
    }
}
