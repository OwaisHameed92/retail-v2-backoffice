<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceLookup;
use App\Domain\Licensing\Api\Support\LicenceReply;
use App\Domain\Licensing\Api\Support\LicenceToken;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Api\Support\TillStatus;
use App\Domain\Licensing\Api\Support\WrongKeyLimiter;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\BranchLicenceTerm;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Actions\RecordTillIds;
use App\Domain\Sync\Support\SyncKeyDelivery;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
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
 *    as `apiKey` (SyncKeyDelivery).
 */
class ActivateLicence
{
    public function __construct(
        private readonly LicenceLookup $lookup,
        private readonly LicenceAlerts $alerts,
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
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
    public function handle(#[SensitiveParameter] string $licenceKey, TillRequest $till): array
    {
        $now = CarbonImmutable::now()->startOfSecond();
        $licence = $this->find($licenceKey, $till);

        // Checked before the transaction so the alert and history are kept when the reply is an error.
        $this->refuse($licence, $till, $now, raiseAlert: true);
        $this->tillIds->check($licence, $till);

        return DB::transaction(function () use ($licence, $till, $now) {
            $licence = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->id);
            $this->refuse($licence, $till, $now, raiseAlert: false);

            if ($licence->isBound()) {
                $this->reinstall($licence, $till, $now);
            } else {
                $this->bind($licence, $till, $now);
            }

            $this->tillIds->handle($licence, $till);

            $state = LicenceState::for($licence, $now);
            $claims = $this->tokens->claims($licence, $state, $now);
            $token = $this->tokens->issue($licence, $claims);
            $licence->save();

            $status = TillStatus::of($state, $claims->expiresAt, $now);
            $link = $this->syncKeys->forActivation($licence, $till, $claims, $status);

            return $this->reply->activation($licence, $state, $claims, $status, $token, $now) + $link;
        });
    }

    /**
     * @throws ApiException
     */
    private function find(#[SensitiveParameter] string $licenceKey, TillRequest $till): Licence
    {
        $this->wrongKeys->ensureAllowed($till->installId);

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
            $this->wrongKeys->hit($till->installId);

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

    /**
     * @throws ApiException licence.seat_limit
     */
    private function bind(Licence $licence, TillRequest $till, CarbonImmutable $now): void
    {
        $maxRegisters = LicenceToken::maxRegisters($licence->branch);
        $inUse = LicenceToken::registersInUse($licence->branch_id, $licence->id);

        if ($inUse >= $maxRegisters) {
            throw LicenceApiErrors::seatLimit($maxRegisters, $inUse);
        }

        $before = self::snapshot($licence);
        $firstActivation = $licence->activated_at === null;

        if ($firstActivation) {
            $this->startTerms($licence, $now);
        }

        $licence->device_id = $till->installId;
        $licence->existing_ids = $till->existingIds;
        $licence->bound_at = $now;
        TillAudit::touch($licence, $till, $now);
        $licence->save();

        $companyTrialEndsAt = $firstActivation ? $this->startCompanyTrial($licence) : null;
        $this->devices->record($licence, $till, DeviceHistory::ACTIVATED, $now);

        $this->audit->record($firstActivation ? 'licence.activated' : 'licence.device_bound', $licence, $before, self::snapshot($licence), $till, array_filter([
            'os' => $till->osLabel(),
            'company_trial_ends_at' => $companyTrialEndsAt,
        ]));
    }

    /**
     * First activation: the branch's kind and length when it has one (module 1.11), else start the plan's trial
     * (or the paid term renewed before activation).
     */
    private function startTerms(Licence $licence, CarbonImmutable $now): void
    {
        $plan = $licence->plan;
        $licence->activated_at = $now;

        if ($licence->branch !== null && BranchLicenceTerm::applyDates($licence, $licence->branch, $now)) {
            return;
        }

        if (! LicenceTerms::isPaid($licence)) {
            $licence->trial_ends_at = $now->addDays($plan->trial_days ?? 7);
        }

        if ($plan !== null) {
            $licence->grace_days = LicenceTerms::graceDaysFor($licence, $plan);
        }

        $licence->status = LicenceTerms::naturalStatus($licence, $now);
    }

    /** A trial company's 7-day trial starts on its first till activation (DECISIONS, module 1.2). */
    private function startCompanyTrial(Licence $licence): ?string
    {
        if ($licence->trial_ends_at === null || LicenceTerms::isPaid($licence)) {
            return null;
        }

        $company = Company::query()->lockForUpdate()->find($licence->company_id);

        if ($company === null || $company->status !== CompanyStatus::Trial || $company->trial_ends_at !== null) {
            return null;
        }

        $company->trial_ends_at = Carbon::instance($licence->trial_ends_at);
        $company->save();

        return $licence->trial_ends_at->toIso8601String();
    }

    /** The bound install activated again (retry or reinstall): no binding change, only its details. */
    private function reinstall(Licence $licence, TillRequest $till, CarbonImmutable $now): void
    {
        $before = ['device_name' => $licence->device_name, 'install_code' => $licence->install_code];

        $licence->existing_ids = $till->existingIds ?? $licence->existing_ids;
        TillAudit::touch($licence, $till, $now);
        $licence->save();

        $this->devices->record($licence, $till, DeviceHistory::REINSTALLED, $now);
        $this->audit->record('licence.reinstalled', $licence, $before, ['device_name' => $licence->device_name, 'install_code' => $licence->install_code], $till);
    }

    /**
     * @return array<string, mixed>
     */
    private static function snapshot(Licence $licence): array
    {
        return [
            'status' => $licence->status->value,
            'activated_at' => $licence->activated_at?->toIso8601String(),
            'trial_ends_at' => $licence->trial_ends_at?->toIso8601String(),
            'grace_days' => $licence->grace_days,
            'device_id' => $licence->device_id,
            'device_name' => $licence->device_name,
            'install_code' => $licence->install_code,
        ];
    }
}
