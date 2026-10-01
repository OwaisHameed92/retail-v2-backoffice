<?php

namespace App\Domain\Privacy\Models;

use App\Domain\Shared\Casts\UtcDateTimeCast;
use App\Domain\Tenancy\Concerns\BelongsToCompany;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * A business's customer data retention (module 7.7): keep a customer's details for `retention_months` after their
 * last activity (null = keep until asked). The daily `privacy:retention` run counts who is past it (`due_count`) and
 * anonymises them only when `auto_anonymise` is on.
 *
 * @property string $company_id
 * @property int|null $retention_months
 * @property bool $auto_anonymise
 * @property CarbonImmutable|null $last_checked_at
 * @property int $due_count
 * @property int|null $updated_by_user_id
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 */
class PrivacySettings extends Model
{
    use BelongsToCompany;

    public const MIN_MONTHS = 6;

    public const MAX_MONTHS = 120;

    public $incrementing = false;

    protected $table = 'company_privacy_settings';

    protected $primaryKey = 'company_id';

    protected $keyType = 'string';

    protected $guarded = [];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'retention_months' => 'integer',
            'auto_anonymise' => 'boolean',
            'due_count' => 'integer',
            'last_checked_at' => UtcDateTimeCast::class,
            'created_at' => UtcDateTimeCast::class,
            'updated_at' => UtcDateTimeCast::class,
        ];
    }

    /** The current business's settings (unsaved defaults when none yet). Runs in the company scope. */
    public static function current(): self
    {
        return self::query()->first() ?? new self(['retention_months' => null, 'auto_anonymise' => false, 'due_count' => 0]);
    }
}
