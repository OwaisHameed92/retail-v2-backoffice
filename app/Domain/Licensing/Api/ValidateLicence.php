<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceReply;
use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Api\Support\TillStatus;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Support\SyncKeyDelivery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/licence/validate` per till (contract v1.4.1 §17.15.2, §17.5): the daily check-in. A licence
 * problem is a 200 with a `status`, never an error.
 *
 * - The licence (`licenceId`) bound to this `installId` → records the check-in (time, app version, OS, device
 *   name, install code, till clock skew, clock watermark, lock state) and answers active / expiring / expired /
 *   suspended / revoked. A new token only when the till holds another one, its kid is not trusted, or anything
 *   in it changed; else `licenceToken: null`.
 * - Module 2.1: the branch's main till with `cloud_sync` gets a (new) sync key as `apiKey` when it has none or
 *   an admin asked for a rotation (SyncKeyDelivery); else `apiKey: null`.
 * - An install that was released from the key (admin Release, devices/deactivate, reissued key) → `released`.
 * - Anything else → 404 key.not_found (plus a `deviceMismatch` alert when the key is bound to another install).
 */
class ValidateLicence
{
    public function __construct(
        private readonly LicenceAlerts $alerts,
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
        private readonly LicenceToken $tokens,
        private readonly LicenceReply $reply,
        private readonly SyncKeyDelivery $syncKeys,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException key.not_found
     */
    public function handle(string $licenceId, string $tokenSha256, TillRequest $till): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $licence = Licence::withoutCompanyScope()->find($licenceId) ?? throw LicenceApiErrors::keyNotFound();

        if ($licence->device_id !== $till->installId) {
            return $this->notBound($licence, $till, $now);
        }

        return DB::transaction(function () use ($licence, $tokenSha256, $till, $now) {
            $licence = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->id);

            if ($licence->device_id !== $till->installId) {
                throw LicenceApiErrors::keyNotFound();
            }

            $this->record($licence, $till, $now);

            $state = LicenceState::for($licence, $now);
            $claims = $this->tokens->claims($licence, $state, $now);
            $status = TillStatus::of($state, $claims->expiresAt, $now);
            $token = TillStatus::trades($status) && $this->tokens->needsNew($licence, $claims, $tokenSha256, $till->trustedKids, $till->approverKids)
                ? $this->tokens->issue($licence, $claims)
                : null;
            $licence->save();
            $link = $this->syncKeys->forValidation($licence, $till, $claims, $status);

            return $this->reply->validation($licence, $state, $claims, $status, $token, $now, $link);
        });
    }

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException key.not_found
     */
    private function notBound(Licence $licence, TillRequest $till, CarbonImmutable $now): array
    {
        if (DeviceHistory::wasReleased($licence, $till->installId)) {
            $this->devices->record($licence, $till, DeviceHistory::RELEASED, $now);

            return $this->reply->released($now);
        }

        if ($licence->isBound()) {
            $this->alerts->raise($licence, LicenceAlertType::DeviceMismatch, $till, ['attempted' => 'validate']);
            $this->devices->record($licence, $till, DeviceHistory::REJECTED, $now);
        }

        throw LicenceApiErrors::keyNotFound();
    }

    private function record(Licence $licence, TillRequest $till, CarbonImmutable $now): void
    {
        $before = ['last_app_version' => $licence->last_app_version, 'lock_locked' => $licence->lock_locked, 'lock_reason' => $licence->lock_reason];

        TillAudit::touch($licence, $till, $now);
        $licence->last_validated_at = $now;
        $licence->clock_watermark_at = $till->clockWatermarkUtc ?? $licence->clock_watermark_at;

        if ($till->locked !== null) {
            $licence->lock_locked = $till->locked;
            $licence->lock_reason = $till->locked && $till->lockReason !== null ? mb_substr($till->lockReason, 0, 40) : null;
        }

        $this->devices->record($licence, $till, DeviceHistory::CHECKED_IN, $now);

        if ($before['last_app_version'] !== null && $before['last_app_version'] !== $licence->last_app_version) {
            $this->audit->record('licence.app_updated', $licence, ['last_app_version' => $before['last_app_version']], ['last_app_version' => $licence->last_app_version], $till);
        }

        // Audited when the till locks or unlocks (not the first "unlocked" report).
        $changed = $before['lock_locked'] !== $licence->lock_locked || $before['lock_reason'] !== $licence->lock_reason;

        if ($changed && ($before['lock_locked'] !== null || $licence->lock_locked === true)) {
            $this->audit->record('licence.lock_changed', $licence, ['lock_locked' => $before['lock_locked'], 'lock_reason' => $before['lock_reason']], ['lock_locked' => $licence->lock_locked, 'lock_reason' => $licence->lock_reason], $till);
        }
    }
}
