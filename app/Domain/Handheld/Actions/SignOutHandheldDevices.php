<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Actions\RecordAudit;
use App\Models\User;
use Carbon\CarbonImmutable;

/**
 * "Sign out devices" on the portal: revokes every live token of a handheld user, so each device must sign in again.
 * Also used by a password reset and a deactivation (`$audit` false: those record their own entry).
 *
 *     $signedOut = app(SignOutHandheldDevices::class)->handle($handheld, $request->user());
 */
final class SignOutHandheldDevices
{
    public function __construct(private readonly RecordAudit $audit) {}

    /**
     * @return int tokens revoked
     */
    public function handle(HandheldUser $handheld, User $by, bool $audit = true): int
    {
        $revoked = HandheldToken::query()->where('handheld_user_id', $handheld->id)->whereNull('revoked_at')
            ->update(['revoked_at' => CarbonImmutable::now(), 'updated_at' => CarbonImmutable::now()]);

        if ($audit) {
            $this->audit->handle('handheld_user.signed_out', $handheld, null, null, ['devices' => $revoked], actor: $by);
        }

        return $revoked;
    }
}
