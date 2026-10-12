<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Models\Partner;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Support\AuditChanges;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Admin → Settings → Partners: creates a partner or changes its name, webhook URL and whether it is active (an
 * inactive partner's keys are refused and no webhook is sent). The webhook URL must be HTTPS (plain HTTP only on a
 * local or testing app). Audited.
 */
class SavePartner
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @throws ValidationException
     */
    public function handle(?Partner $partner, string $name, ?string $webhookUrl, bool $active, ?string $adminId = null): Partner
    {
        $name = trim($name);
        $webhookUrl = trim((string) $webhookUrl) === '' ? null : trim((string) $webhookUrl);

        if ($name === '') {
            throw ValidationException::withMessages(['name' => 'Enter the partner’s name.']);
        }

        if ($webhookUrl !== null && ! str_starts_with(strtolower($webhookUrl), 'https://') && ! app()->environment(['local', 'testing'])) {
            throw ValidationException::withMessages(['webhook_url' => 'Use an https:// address.']);
        }

        return DB::transaction(function () use ($partner, $name, $webhookUrl, $active, $adminId) {
            if ($partner === null) {
                $partner = Partner::query()->create(['name' => $name, 'webhook_url' => $webhookUrl, 'is_active' => $active, 'created_by_admin_id' => $adminId]);
                $this->audit->handle('partner.created', $partner, null, ['name' => $name, 'webhook_url' => $webhookUrl, 'is_active' => $active]);

                return $partner;
            }

            $partner->fill(['name' => $name, 'webhook_url' => $webhookUrl, 'is_active' => $active]);
            [$before, $after] = AuditChanges::of($partner);

            if ($after !== []) {
                $partner->save();
                $this->audit->handle('partner.updated', $partner, $before, $after);
            }

            return $partner;
        });
    }
}
