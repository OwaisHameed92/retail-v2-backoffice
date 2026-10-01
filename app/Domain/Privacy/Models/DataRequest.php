<?php

namespace App\Domain\Privacy\Models;

use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Enums\DataRequestType;
use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Models\Company;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A customer's data request (module 7.7): an export (subject access) or an erasure. Never holds the customer's
 * personal details, only their id; `till_steps` lists what must still be done on the tills for till-owned records.
 *
 * @property string $id
 * @property string $company_id
 * @property string|null $customer_id
 * @property DataRequestType $type
 * @property DataRequestStatus $status
 * @property string $source owner | retention
 * @property int|null $requested_by_user_id
 * @property list<array{key: string, text: string, count: int}>|null $till_steps
 * @property CarbonImmutable|null $till_done_at
 * @property int|null $till_done_by_user_id
 * @property string|null $note
 * @property CarbonImmutable|null $completed_at
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class DataRequest extends Model
{
    use BelongsToCompany, HasUlids;

    protected $guarded = ['id', 'company_id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'type' => DataRequestType::class,
            'status' => DataRequestStatus::class,
            'till_steps' => 'array',
            'till_done_at' => UtcDateTimeCast::class,
            'completed_at' => UtcDateTimeCast::class,
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by_user_id');
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
