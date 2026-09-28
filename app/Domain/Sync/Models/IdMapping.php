<?php

namespace App\Domain\Sync\Models;

use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Enums\IdMapAction;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One till id → one of our ids (module 2.1, contract v1.3.3 §17.3 step 3). Tenant-owned: sync and licence API
 * code reads it with `withoutCompanyScope()` and always filters by company. Written only by RecordTillIds.
 *
 * `branch_id` = our branch whose till sent it (for a company alias: the branch that uses it, so pull can echo the
 * right company id back). `install_id` = the PC that sent it first.
 *
 * @property int $id
 * @property IdKind $kind
 * @property string $till_id
 * @property string $portal_id
 * @property string $company_id
 * @property string|null $branch_id
 * @property IdMapAction $action
 * @property string|null $install_id
 * @property CarbonImmutable|null $created_at
 */
class IdMapping extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'id_map';

    /** @var list<string> */
    protected $fillable = ['kind', 'till_id', 'portal_id', 'company_id', 'branch_id', 'action', 'install_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'kind' => IdKind::class,
            'action' => IdMapAction::class,
            'created_at' => 'immutable_datetime',
        ];
    }
}
