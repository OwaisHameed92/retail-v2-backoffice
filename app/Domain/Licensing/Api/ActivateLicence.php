<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceBinder;
use App\Domain\Licensing\Api\Support\LicenceLookup;
use App\Domain\Licensing\Api\Support\LicenceReply;
use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Api\Support\TillStatus;
use App\Domain\Licensing\Api\Support\WrongKeyLimiter;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Actions\RecordTillIds;
use App\Domain\Sync\Support\SyncKeyDelivery;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * `POST /api/v1/licence/activate` (contract v1.4.1 §17.15.1): binds an e-mailed key to the till's install and
 * answers with a signed token for that till.
 *
 * 1. Unknown or malformed key → 404 key.not_found; a replaced key, one revoked before it was ever used, or an
 *    unused key past its activate-by date (module 1.11) → 410 key.expired (both count as wrong keys: 5 per install per 15 minutes, then 429). Revoked after use or
 *    suspended (licence, company, branch or till) → 403 licence.not_active.
 * 2. Not bound → bind to this installId (+ installCode, deviceName, existingIds). First activation starts the
 *    trial (or the paid term renewed before activation) and the company's trial.
 * 3. Bound to this installId → 200 again (retry or reinstall).
 * 4. Bound to another installId → 409 key.already_used + a `sameKeyTwoDevices` alert.
 * 4a. Not bound and the branch already has maxRegisters keys bound → 403 licence.seat_limit.
 * 5. Module 2.1: the till's `existingIds` are recorded in `id_map` (adopt / alias; 409 licence.ids_conflict when
 *    they belong to another business or branch), and the branch's main till with `cloud_sync` gets the sync key
 *    as `apiKey` (SyncKeyDelivery) — unless `$deliverSyncKey` is false (module 2.8: a portal key redeemed at a
 *    linked till, which already holds its branch key).
 */
class ActivateLicence
{
    public function __construct(
        private readonly LicenceLookup $lookup,
        private readonly LicenceAlerts $alerts,
        private readonly DeviceHistory $devices,
        private readonly LicenceBinder $binder,
        private readonly LicenceToken $tokens,
        private readonly LicenceReply $reply,
        private readonly WrongKeyLimiter $wrongKeys,
        private readonly RecordTillIds $tillIds,
        private readonly SyncKeyDelivery $syncKeys,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function handle(#[SensitiveParameter] string $licenceKey, TillRequest $till, bool $deliverSyncKey = true): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $licence = $this->find($licenceKey, $till);

        // Checked before the transaction so the alert and history are kept when the reply is an error.
        $this->refuse($licence, $till, $now, raiseAlert: true);
        $this->tillIds->check($licence, $till);

        return DB::transaction(function () use ($licence, $till, $now, $deliverSyncKey) {
            $licence = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->id);
            $this->refuse($licence, $till, $now, raiseAlert: false);

            if ($licence->isBound()) {
                $this->binder->reinstall($licence, $till, $now);
            } else {
                $this->binder->bind($licence, $till, $now);
            }

            $this->tillIds->handle($licence, $till);

            $state = LicenceState::for($licence, $now);
            $claims = $this->tokens->claims($licence, $state, $now);
            $token = $this->tokens->issue($licence, $claims);
            $licence->save();

            $status = TillStatus::of($state, $claims->expiresAt, $now);
            $link = $deliverSyncKey ? $this->syncKeys->forActivation($licence, $till, $claims, $status) : [];

            return $this->reply->activation($licence, $state, $claims, $status, $token, $now) + $link;
        });
    }

    /**
     * @throws ApiException
     */
    private function find(#[SensitiveParameter] string $licenceKey, TillRequest $till): Licence
    {
        $this->wrongKeys->ensureAllowed($till);

        try {
            $key = LicenceKey::tryParse($licenceKey) ?? throw LicenceApiErrors::keyNotFound();
            $licence = $this->lookup->byKey($key, $till);

            if ($licence->isRevoked() && $licence->activated_at === null) {
                throw LicenceApiErrors::keyExpired();
            }

            // Module 1.11: an unused key must be activated by its activate-by date.
            if ($licence->activated_at === null && $licence->activate_by !== null && $licence->activate_by->isPast()) {
                throw LicenceApiErrors::keyExpired();
            }

            return $licence;
        } catch (ApiException $e) {
            $this->wrongKeys->hit($till);

            throw $e;
        }
    }

    /**
     * @throws ApiException
     */
    private function refuse(Licence $licence, TillRequest $till, CarbonImmutable $now, bool $raiseAlert): void
    {
        if ($licence->isBound() && $licence->device_id !== $till->installId) {
            if ($raiseAlert) {
                $this->alerts->raise($licence, LicenceAlertType::SameKeyTwoDevices, $till);
                $this->devices->record($licence, $till, DeviceHistory::REJECTED, $now);
            }

            throw LicenceApiErrors::keyAlreadyUsed($licence);
        }

        $state = LicenceState::for($licence, $now);

        if ($state->status === LicenceStatus::Revoked || $state->status === LicenceStatus::Suspended) {
            throw LicenceApiErrors::notActive($state->status->value, $state->reason);
        }
    }
}
