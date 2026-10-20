<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * Sets a new password chosen by the manager and signs out every device of that handheld user. Audited as
 * `handheld_user.password_reset` (the password itself never).
 */
final class ResetHandheldPassword
{
    public function __construct(private readonly SignOutHandheldDevices $signOut, private readonly RecordAudit $audit) {}

    /**
     * @return int devices signed out
     */
    public function handle(HandheldUser $handheld, #[SensitiveParameter] string $password, User $by): int
    {
        return DB::transaction(function () use ($handheld, $password, $by) {
            $handheld->forceFill(['password' => $password])->save();
            $devices = $this->signOut->handle($handheld, $by, audit: false);
            $this->audit->handle('handheld_user.password_reset', $handheld, null, null, ['devices_signed_out' => $devices], actor: $by);

            return $devices;
        });
    }
}
