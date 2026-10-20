<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Switches a handheld user off (every device is signed out at once and further logins answer 403 ACCOUNT_INACTIVE)
 * or back on (they sign in again with their password). Audited as `handheld_user.deactivated` / `.reactivated`.
 */
final class SetHandheldUserActive
{
    public function __construct(private readonly SignOutHandheldDevices $signOut, private readonly RecordAudit $audit) {}

    public function handle(HandheldUser $handheld, bool $active, User $by): HandheldUser
    {
        if ($handheld->is_active === $active) {
            return $handheld;
        }

        return DB::transaction(function () use ($handheld, $active, $by) {
            $handheld->forceFill(['is_active' => $active])->save();
            $devices = $active ? 0 : $this->signOut->handle($handheld, $by, audit: false);

            $this->audit->handle($active ? 'handheld_user.reactivated' : 'handheld_user.deactivated', $handheld,
                ['is_active' => ! $active], ['is_active' => $active], $active ? [] : ['devices_signed_out' => $devices], actor: $by);

            return $handheld;
        });
    }
}
