<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHash;
use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Models\LicenceDevice;
use App\Domain\Licensing\Support\InstallRelease;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Sync\Actions\RevokeTillSyncKeys;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Sync\Support\SyncKeySecret;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * `POST /api/v1/devices/deactivate` for a per-till licence (contract v1.4.1 §17.7, §17.15): the till gives its
 * key back (till removed, moving PC), so the key can be activated elsewhere without staff doing Release.
 *
 * - The till is identified by its `installId` (body, else `X-SSPOS-Install-Id`) and `registerId` (the till's
 *   own id from `existingIds`, or our register id). A till we sent the branch's sync key must also send it as
 *   Bearer (§17.7 "the branch key when the till holds one", security review H2). Every release raises a
 *   `tillDeactivated` alert; the route is rate limited per install and per IP.
 * - Optional `tokenSha256` (ANSWERS-2026-10-06 "Purane khule sawal" 2, additive): when sent it must be the hash of
 *   the token we issued this install (current or the one before, LicenceToken::isHeld, as licence/validate), else
 *   403 `device.token_mismatch` + a `tokenMismatch` alert and nothing released. Absent (tills up to 0.1.51): as before.
 * - Idempotent by registerId: a till already released gets the same reply. Unknown → 404 device.not_found.
 * - Reply: `seat: "deactivated"`, the branch's seats in use and maxRegisters. `apiKeyRevoked`: true when this till
 *   (the branch's main till) had been sent the branch's sync key in a licence reply — that key is revoked and rotated
 *   on the portal so a restored old PC cannot push (v1.4.1, ANSWERS-2026-09-29 §1, RevokeTillSyncKeys); else false
 *   (the till forgets its link either way).
 * - No transfer code, main till included (ANSWERS-2026-09-30-portal point 6, option b): §17.7's code is redeemed
 *   only by `devices/activate`, which is never built; the same licence key activates on the new PC instead
 *   (licence/activate), and the main till's `messages[]` tells the owner so.
 */
class DeactivateDevice
{
    public function __construct(
        private readonly InstallRelease $release,
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
        private readonly RevokeTillSyncKeys $syncKeys,
        private readonly LicenceAlerts $alerts,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException device.not_found, device.token_mismatch, auth.invalid_key
     */
    public function handle(string $registerId, TillRequest $till, string $reason, ?string $note, #[SensitiveParameter] ?string $bearer = null, ?string $tokenSha256 = null): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $bound = $this->bound($registerId, $till, lock: false);

        if ($bound !== null) {
            $this->ensureToken($bound, $till, $tokenSha256);
            $this->ensureBranchKey($bound, $till, $bearer, $now);
        }

        $fresh = false;
        $licence = DB::transaction(function () use ($registerId, $till, $reason, $note, $now, $tokenSha256, &$fresh) {
            $bound = $this->bound($registerId, $till, lock: true);

            if ($bound === null) {
                return $this->released($registerId, $till) ?? throw LicenceApiErrors::deviceNotFound();
            }

            if ($tokenSha256 !== null && ! LicenceToken::isHeld($bound, $tokenSha256)) {
                throw LicenceApiErrors::tokenMismatch();
            }

            $fresh = true;

            $this->devices->record($bound, $till, DeviceHistory::RELEASED, $now);
            $before = $this->release->apply($bound, $now);
            $this->audit->record('licence.released', $bound, $before, InstallRelease::after(), $till, array_filter([
                'reason' => mb_substr($reason, 0, 40),
                'note' => $note !== null ? mb_substr($note, 0, 200) : null,
            ]));

            return $bound;
        });

        if ($fresh) {
            $this->alerts->raise($licence, LicenceAlertType::TillDeactivated, $till, ['reason' => mb_substr($reason, 0, 40)]);
        }

        $apiKeyRevoked = $licence->branch !== null && $this->syncKeys->handle($licence->branch, $till->installId);

        return [
            'registerId' => $registerId,
            'seat' => 'deactivated',
            'seatsInUse' => LicenceToken::registersInUse($licence->branch_id),
            'maxRegisters' => LicenceToken::maxRegisters($licence->branch),
            'apiKeyRevoked' => $apiKeyRevoked,
            'transferCode' => null,
            'transferCodeExpiresAt' => null,
            'portalTimeUtc' => ApiDate::format($now),
            'messages' => $apiKeyRevoked ? [self::nextStep($licence)] : [],
        ];
    }

    /**
     * The main till's next step, en-GB (ANSWERS-2026-09-30-portal point 6; sample deactivate-reply.main-till.same-key.json,
     * till 0.1.15). Stable id: a repeat gives the same reply.
     *
     * @return array<string, mixed>
     */
    private static function nextStep(Licence $licence): array
    {
        return [
            'id' => 'released-'.$licence->id,
            'level' => 'info',
            'title' => 'Till released',
            'text' => 'Activate this same licence key on the new PC (Settings → Licence → Enter key). Restore your backup there first. No transfer code is needed.',
            'showFromUtc' => null,
            'showUntilUtc' => null,
            'dismissible' => false,
            'link' => null,
        ];
    }

    private function bound(string $registerId, TillRequest $till, bool $lock): ?Licence
    {
        return Licence::withoutCompanyScope()->where('device_id', $till->installId)->when($lock, fn ($q) => $q->lockForUpdate())->get()
            ->first(fn (Licence $licence) => self::isRegister($licence, $registerId));
    }

    /**
     * A till that sends `tokenSha256` must hold the token we issued this install; one that sends none is checked as
     * before (the branch key when it holds one).
     *
     * @throws ApiException device.token_mismatch
     */
    private function ensureToken(Licence $licence, TillRequest $till, ?string $tokenSha256): void
    {
        if ($tokenSha256 !== null && ! LicenceToken::isHeld($licence, $tokenSha256)) {
            $this->alerts->raise($licence, LicenceAlertType::TokenMismatch, $till, ['attempted' => 'deactivate']);

            throw LicenceApiErrors::tokenMismatch();
        }
    }

    /**
     * Security review H2, §17.7 "Auth: the branch key when the till holds one": a till we sent the branch's sync key
     * (the main till) must send a usable key of its branch as `Authorization: Bearer`, else 401 auth.invalid_key and a
     * `deviceMismatch` alert. A second till holds no key, so its ids are all the contract asks for (rate limited,
     * audited and alerted; see DECISIONS "deactivate proof").
     *
     * @throws ApiException auth.invalid_key
     */
    private function ensureBranchKey(Licence $licence, TillRequest $till, #[SensitiveParameter] ?string $bearer, CarbonImmutable $now): void
    {
        $keys = SyncKey::withoutCompanyScope()->where('branch_id', $licence->branch_id)->get()->filter(fn (SyncKey $key) => $key->isUsable($now));

        if (! $keys->contains(fn (SyncKey $key) => $key->delivered_install_id === $till->installId)) {
            return;
        }

        $bearer = trim((string) $bearer);
        $candidates = $bearer !== '' && SyncKeySecret::looksValid($bearer) ? SyncKeySecret::hashCandidates($bearer) : [];

        if (! $keys->contains(fn (SyncKey $key) => in_array($key->key_hash, $candidates, true))) {
            $this->alerts->raise($licence, LicenceAlertType::DeviceMismatch, $till, ['attempted' => 'deactivate']);

            throw SyncApiErrors::invalidKey();
        }
    }

    /** An install already released from a licence of this register (a repeated deactivate). */
    private function released(string $registerId, TillRequest $till): ?Licence
    {
        $licenceIds = LicenceDevice::withoutCompanyScope()
            ->where('device_hash', DeviceHash::of($till->installId))
            ->whereNotNull('released_at')
            ->pluck('licence_id');

        return Licence::withoutCompanyScope()->whereIn('id', $licenceIds)->get()
            ->first(fn (Licence $licence) => self::isRegister($licence, $registerId));
    }

    private static function isRegister(Licence $licence, string $registerId): bool
    {
        return $licence->register_id === $registerId || ($licence->existing_ids['registerId'] ?? null) === $registerId;
    }
}
