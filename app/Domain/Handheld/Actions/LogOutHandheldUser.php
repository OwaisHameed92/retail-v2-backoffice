<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Shared\Actions\RecordAudit;
use Carbon\CarbonImmutable;

/**
 * `POST /api/pda/logout`: revokes the token the call was made with (other devices stay signed in). Audited as
 * `handheld.logout` with the handheld user as the actor.
 */
final class LogOutHandheldUser
{
    public function __construct(private readonly RecordAudit $audit) {}

    public function handle(HandheldUser $user, HandheldToken $token): void
    {
        if ($token->isRevoked()) {
            return;
        }

        $token->forceFill(['revoked_at' => CarbonImmutable::now()])->save();
        $this->audit->handle('handheld.logout', $user, null, null, ['device' => $token->device_name], actor: $user);
    }
}
