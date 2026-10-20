<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldAccess;
use App\Domain\Handheld\Support\HandheldTokenSecret;
use App\Domain\Handheld\Support\PdaErrors;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * Who is calling `/api/pda/*`: the Bearer token → its live token row and handheld user (SHA-256 lookup, then a
 * constant-time compare). No, malformed, unknown or revoked token → 401 UNAUTHENTICATED; a switched-off account,
 * closed shop or business on hold → 403 (HandheldAccess). The token's last use is written at most once a minute.
 */
final class ResolveHandheldToken
{
    /**
     * @return array{0: HandheldToken, 1: HandheldUser, 2: Company, 3: Branch}
     *
     * @throws ApiException
     */
    public function handle(#[SensitiveParameter] ?string $bearer): array
    {
        $bearer = trim((string) $bearer);

        if ($bearer === '' || ! HandheldTokenSecret::looksValid($bearer)) {
            throw PdaErrors::unauthenticated();
        }

        $hash = HandheldTokenSecret::hash($bearer);
        $token = HandheldToken::withoutCompanyScope()->where('token_hash', $hash)->first();

        if ($token === null || ! hash_equals($token->token_hash, $hash) || $token->isRevoked()) {
            throw PdaErrors::unauthenticated();
        }

        $user = HandheldUser::withoutCompanyScope()->where('company_id', $token->company_id)->find($token->handheld_user_id);

        if (! $user instanceof HandheldUser) {
            throw PdaErrors::unauthenticated();
        }

        [$company, $shop] = HandheldAccess::assertAllowed($user);
        $this->touch($token);

        return [$token, $user, $company, $shop];
    }

    private function touch(HandheldToken $token): void
    {
        $now = CarbonImmutable::now();
        $every = max(1, (int) config('handheld.touch_every_seconds', 60));

        if ($token->last_used_at === null || $token->last_used_at->addSeconds($every)->lessThanOrEqualTo($now)) {
            HandheldToken::withoutCompanyScope()->whereKey($token->id)->update(['last_used_at' => $now]);
            $token->last_used_at = $now;
        }
    }
}
