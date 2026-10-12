<?php

namespace App\Domain\Partners\Actions;

use App\Domain\Partners\Models\PartnerKey;
use App\Domain\Partners\Support\PartnerApiErrors;
use App\Domain\Partners\Support\PartnerKeySecret;
use App\Domain\Shared\Exceptions\ApiException;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * Who calls `/api/partner/v1/*`: the Bearer partner key → its key row (HMAC lookup, then a constant-time compare of
 * the hashes). No, malformed or unknown key → 401 auth.invalid_key; revoked key or inactive partner → 401
 * auth.key_revoked. The key's last use is written at most once a minute.
 */
class AuthenticatePartner
{
    /**
     * @throws ApiException
     */
    public function handle(#[SensitiveParameter] ?string $bearer): PartnerKey
    {
        $bearer = trim((string) $bearer);

        if ($bearer === '' || ! PartnerKeySecret::looksValid($bearer)) {
            throw PartnerApiErrors::invalidKey();
        }

        $candidates = PartnerKeySecret::hashCandidates($bearer);
        $key = PartnerKey::query()->with('partner')->whereIn('key_hash', $candidates)->first();

        if ($key === null || ! $this->matches($key->key_hash, $candidates)) {
            throw PartnerApiErrors::invalidKey();
        }

        if ($key->isRevoked() || $key->partner === null || ! $key->partner->is_active) {
            throw PartnerApiErrors::keyRevoked();
        }

        $this->touch($key);

        return $key;
    }

    /**
     * @param  list<string>  $candidates
     */
    private function matches(string $stored, array $candidates): bool
    {
        $found = false;

        foreach ($candidates as $candidate) {
            $found = hash_equals($stored, $candidate) || $found;
        }

        return $found;
    }

    private function touch(PartnerKey $key): void
    {
        $now = CarbonImmutable::now();
        $every = max(1, (int) config('partners.touch_every_seconds', 60));

        if ($key->last_used_at === null || $key->last_used_at->addSeconds($every)->lessThanOrEqualTo($now)) {
            PartnerKey::query()->whereKey($key->id)->update(['last_used_at' => $now]);
            $key->last_used_at = $now;
        }
    }
}
