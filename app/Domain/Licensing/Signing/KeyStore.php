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
 * secret; a retired key verifies until retired_at + keep days; a kid is never reused.
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
     * Every stored key, newest first (for licence:keys:list).
     *
     * @return list<SigningKey>
     */
    public function all(): array
    {
        return LicenceSigningKey::query()
            ->orderByDesc('created_at')->orderByDesc('kid')
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

            $pair = Ed25519Jws::newKeyPair();

            $row = new LicenceSigningKey;
            $row->forceFill([
                'kid' => $this->nextKid($now),
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
     * `lk<year>-<nn>`, e.g. lk2026-01. The sequence continues from the highest kid ever kept for that year;
     * the newest key is never pruned, so a kid is never handed out twice.
     */
    private function nextKid(CarbonImmutable $now): string
    {
        $prefix = 'lk'.$now->format('Y').'-';

        $max = LicenceSigningKey::query()->where('kid', 'like', $prefix.'%')->pluck('kid')
            ->map(fn (string $kid) => preg_match('/^lk\d{4}-(\d+)$/', $kid, $m) === 1 ? (int) $m[1] : 0)
            ->max() ?? 0;

        return $prefix.str_pad((string) ($max + 1), 2, '0', STR_PAD_LEFT);
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
