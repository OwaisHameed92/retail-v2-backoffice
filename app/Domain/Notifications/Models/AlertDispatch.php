<?php

namespace App\Domain\Notifications\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * An alert email that went to one user (module 7.8): per urgent problem (`open` until its "resolved" email went,
 * then `resolved`) and per daily digest (`sent`). Keeps the 6-hour rule: a problem that clears and comes back within
 * 6 hours of the last email is not emailed again.
 *
 * @property string $id
 * @property string $company_id
 * @property int $user_id
 * @property string $alert_type
 * @property string $subject_key
 * @property string $state
 * @property CarbonImmutable|null $notified_at
 * @property CarbonImmutable|null $resolved_at
 */
class AlertDispatch extends Model
{
    use BelongsToCompany, HasPortalUlid;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    public const SENT = 'sent';

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'alert_type', 'subject_key', 'state', 'notified_at', 'resolved_at'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'notified_at' => 'immutable_datetime', 'resolved_at' => 'immutable_datetime'];
    }
}
