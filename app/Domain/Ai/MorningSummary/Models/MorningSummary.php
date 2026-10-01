<?php

namespace App\Domain\Ai\MorningSummary\Models;

use App\Domain\Tenancy\Concerns\BelongsToCompany;
use App\Domain\Tenancy\Concerns\HasPortalUlid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

/**
 * The morning summary one user got for one trading day (module 6.3): the computed facts as emailed and the AI
 * narrative (null when AI is not set up, not in the plan, or the text failed the number check). Tenant-owned; the
 * 07:00 job writes it with `withoutCompanyScope()` for a known company. Pruned after `ai.retention_days`.
 *
 * @property string $id
 * @property string $company_id
 * @property int $user_id
 * @property string $trading_day London trading day, Y-m-d (no date cast: compared as text)
 * @property string $scope_key
 * @property string $facts_hash
 * @property array<string, mixed> $facts
 * @property string|null $narrative
 * @property string $narrative_status
 * @property string|null $model
 */
class MorningSummary extends Model
{
    use BelongsToCompany, HasPortalUlid, MassPrunable;

    public const WRITTEN = 'written';

    /** @var list<string> */
    protected $fillable = ['company_id', 'user_id', 'trading_day', 'scope_key', 'facts_hash', 'facts', 'narrative', 'narrative_status', 'model'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return ['user_id' => 'integer', 'facts' => 'array'];
    }

    /**
     * @return Builder<static>
     */
    public function prunable(): Builder
    {
        return self::withoutCompanyScope()->where('created_at', '<', now()->subDays(max(1, (int) config('ai.retention_days', 90))));
    }
}
