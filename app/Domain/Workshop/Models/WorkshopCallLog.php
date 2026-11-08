<?php

namespace App\Domain\Workshop\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * One call BikeBook made to the connector (WORKSHOP-BIKEBOOK.md §2, §8): method, path (query string without any
 * key), status, time taken, BikeBook's request id. Never a body, token, key or address. The last 1,000 per
 * connection are kept (CallLogger).
 *
 * @property int $id
 * @property string $company_id
 * @property string $connection_id
 * @property string $method
 * @property string $path
 * @property int $status
 * @property int $duration_ms
 * @property string|null $request_id
 * @property string|null $auth
 * @property string|null $error
 * @property CarbonImmutable $created_at
 */
class WorkshopCallLog extends Model
{
    use BelongsToCompany;

    public const UPDATED_AT = null;

    protected $table = 'workshop_call_logs';

    protected $guarded = ['id'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'immutable_datetime',
        ];
    }
}
