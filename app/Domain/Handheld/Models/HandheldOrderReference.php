<?php

namespace App\Domain\Handheld\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * The handheld app's `client_reference` (a UUID it generates) for one purchase order create, unique per business and
 * shop (`handheld_order_refs_shop_reference_unique`). A repeated create with the same reference answers the order
 * already made instead of drafting a second one (docs/pda-api.md "Idempotency"). Portal only, never sent to a till.
 *
 * @property string $id
 * @property string $company_id
 * @property string $branch_id
 * @property string $client_reference
 * @property string|null $purchase_order_id
 * @property string|null $handheld_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class HandheldOrderReference extends Model
{
    use BelongsToCompany, HasPortalUlid;

    /** @var list<string> */
    protected $fillable = ['company_id', 'branch_id', 'client_reference', 'purchase_order_id', 'handheld_user_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'created_at' => 'immutable_datetime',
            'updated_at' => 'immutable_datetime',
        ];
    }
}
