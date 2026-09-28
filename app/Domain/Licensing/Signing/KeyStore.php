<?php

namespace App\Domain\Licensing\Signing;

use App\Domain\Licensing\Signing\Exceptions\NoActiveSigningKey;
use App\Domain\Licensing\Signing\Models\LicenceSigningKey;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Config\Repository as Config;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Cache;

/**
 * Persists licence signing keys in `licence_signing_keys`.
 *
 * Invariants: at most one key is active (the newest wins if that is ever broken); a retired key has no
 * secret; a retired key verifies until retired_at + keep days; a kid (k + 8 hex of SHA-256(public key),
 * contract §17.2) is never reused: a new pair whose kid is already stored is thrown away and redrawn.
 */
class KeyStore
{
    private const LOCK = 'licence-signing-keys:write';

    public function __construct(
        private readonly Config $config,
        private readonly ConnectionInterface $db,
    ) {}

    public function keepDays(): int
    {
        return max(0, (int) $this->config->get('licence.signing_keys.retired_keep_days', 60));
    }

    public function hasActive(): bool
    {
        return $this->activeQuery()->exists();
    }

    /**
     * @throws NoActiveSigningKey
     */
    public function active(): SigningKey
    {
        $row = $this->activeQuery()->first();

        if ($row === null) {
            throw new NoActiveSigningKey;
        }

        return $row->toSigningKey();
    }

    /**
     * A key that may verify tokens: active, or retired less than keep days ago. Null otherwise.
     */
    public function findForVerification(string $kid): ?SigningKey
    {
        $row = $this->verifiableQuery()->where('kid', $kid)->first();

        return $row?->toSigningKey();
    }

    /**
     * Keys that may verify tokens, active first then newest first. Used for the JWKS.
     *
     * @return list<SigningKey>
     */
    public function verificationKeys(): array
    {
        return $this->verifiableQuery()
            ->orderByDesc('is_active')->orderByDesc('created_at')->orderByDesc('kid')
            ->get()->map(fn (LicenceSigningKey $row) => $row->toSigningKey())->values()->all();
    }

    /**
     * Every stored key, active first then newest first (for licence:keys:list).
     *
     * @return list<SigningKey>
     */
    public function all(): array
    {
        return LicenceSigningKey::query()
            ->orderByDesc('is_active')->orderByDesc('created_at')->orderByDesc('kid')
            ->get()->map(fn (LicenceSigningKey $row) => $row->toSigningKey())->values()->all();
    }

    /**
     * Creates a new active key and retires the current active key(s) in one transaction.
     *
     * @return array{key: SigningKey, retired: list<string>} The new key and the kids it replaced.
     */
    public function createActive(): array
    {
        return Cache::lock(self::LOCK, 30)->block(10, fn () => $this->db->transaction(function () {
            $now = CarbonImmutable::now('UTC');

            $retiring = LicenceSigningKey::query()->where('is_active', true)->lockForUpdate()->get();

            foreach ($retiring as $old) {
                $old->forceFill(['is_active' => false, 'retired_at' => $now, 'secret_key' => null])->save();
            }

            $pair = $this->newUniquePair();

            $row = new LicenceSigningKey;
            $row->forceFill([
                'kid' => Kid::for($pair['public']),
                'public_key' => Base64Url::encode($pair['public']),
                'secret_key' => Base64Url::encode($pair['secret']),
                'is_active' => true,
            ])->save();

            sodium_memzero($pair['secret']);

            return [
                'key' => $row->toSigningKey(),
                'retired' => $retiring->pluck('kid')->values()->all(),
            ];
        }));
    }

    /**
     * Deletes retired keys past the keep period. Never touches the active key.
     *
     * @return list<string> Kids deleted.
     */
    public function prune(): array
    {
        return Cache::lock(self::LOCK, 30)->block(10, function () {
            $rows = LicenceSigningKey::query()
                ->where('is_active', false)
                ->whereNotNull('retired_at')
                ->where('retired_at', '<=', $this->verifiableSince())
                ->get();

            LicenceSigningKey::query()->whereKey($rows->modelKeys())->delete();

            return $rows->pluck('kid')->values()->all();
        });
    }

    /**
     * Stores the owner's signer certificate on a key. The caller has checked it (ImportSignerCertificate).
     */
    public function saveSignerCert(string $kid, string $certificate): SigningKey
    {
        $row = LicenceSigningKey::query()->where('kid', $kid)->firstOrFail();
        $row->forceFill(['signer_cert' => $certificate])->save();

        return $row->toSigningKey();
    }

    /**
     * @return array{public: string, secret: string}
     */
    private function newUniquePair(): array
    {
        do {
            $pair = Ed25519Jws::newKeyPair();
        } while (LicenceSigningKey::query()->where('kid', Kid::for($pair['public']))->exists());

        return $pair;
    }

    /**
     * @return Builder<LicenceSigningKey>
     */
    private function activeQuery(): Builder
    {
        return LicenceSigningKey::query()->where('is_active', true)->whereNull('retired_at')
            ->orderByDesc('created_at')->orderByDesc('kid');
    }

    /**
     * @return Builder<LicenceSigningKey>
     */
    private function verifiableQuery(): Builder
    {
        return LicenceSigningKey::query()->where(function (Builder $q) {
            $q->where(fn (Builder $a) => $a->where('is_active', true)->whereNull('retired_at'))
                ->orWhere('retired_at', '>', $this->verifiableSince());
        });
    }

    private function verifiableSince(): CarbonImmutable
    {
        return CarbonImmutable::now('UTC')->subDays($this->keepDays());
    }
}
