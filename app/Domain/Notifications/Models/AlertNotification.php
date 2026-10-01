<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * One entry of a user's notifications bell (module 7.8). Removed after {@see self::KEEP_DAYS} days by `model:prune`.
 *
 * @property string $id
 * @property string $company_id
 * @property int $user_id
 * @property string $alert_type
 * @property string $tone danger | warning | success | info
 * @property string $title
 * @property string|null $body
 * @property string|null $url
 * @property CarbonImmutable|null $read_at
 * @property CarbonImmutable|null $created_at
 */
class AlertNotification extends Model
{
    use BelongsToCompany, HasPortalUlid, MassPrunable;

    public const KEEP_DAYS = 90;

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'alert_type', 'tone', 'title', 'body', 'url', 'read_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'read_at' => 'immutable_datetime', 'created_at' => 'immutable_datetime'];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::withoutCompanyScope()->where('created_at', '<', now()->subDays(self::KEEP_DAYS));
    }
}
