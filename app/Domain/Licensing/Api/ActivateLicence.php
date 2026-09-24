<?php

namespace App\Domain\Licensing\Api;

use App\Domain\Licensing\Api\Support\DeviceHistory;
use App\Domain\Licensing\Api\Support\LicenceAlerts;
use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceLookup;
use App\Domain\Licensing\Api\Support\LicenceReply;
use App\Domain\Licensing\Api\Support\TillAudit;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\LicenceState;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * `POST /api/v1/licence/activate`: binds a key to the PC and answers with the licence, a signed token and the
 * company, branch and till rows.
 *
 * - First activation ever: `activated_at` = now; a trial gets `trial_ends_at` = now + plan trial_days and the
 *   plan's trial grace; a licence renewed before activation starts `active` with the paid grace. A trial company
 *   with no trial end yet gets this licence's trial end.
 * - Not bound (first activation, or after "Reset PC"): binds the PC, if the licence can trade (else 403
 *   licence.not_activatable).
 * - Bound to this PC (reinstall): same reply, whatever the status (the token carries it).
 * - Bound to another PC: 409 licence.bound_to_other_device and a `sameKeyTwoDevices` alert.
 * - Revoked: 403 licence.revoked.
 */
class ActivateLicence
{
    public function __construct(
        private readonly LicenceLookup $lookup,
        private readonly LicenceAlerts $alerts,
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
        private readonly LicenceReply $reply,
    ) {}

    /**
     * @return array<string, mixed>
     *
     * @throws ApiException
     */
    public function handle(TillRequest $request): array
    {
        $now = CarbonImmutable::now();
        $licence = $this->lookup->find($request);

        // Checked before the transaction so the alert is kept when the reply is an error.
        $this->refuse($licence, $request, $now, raiseAlert: true);

        $licence = DB::transaction(function () use ($licence, $request, $now) {
            $licence = Licence::withoutCompanyScope()->lockForUpdate()->findOrFail($licence->id);
            $this->refuse($licence, $request, $now, raiseAlert: false);

            if ($licence->isBound()) {
                $this->reinstall($licence, $request, $now);
            } else {
                $this->bind($licence, $request, $now);
            }

            return $licence;
        });

        return $this->reply->activation($licence, $now);
    }

    /**
     * @throws ApiException
     */
    private function refuse(Licence $licence, TillRequest $request, CarbonImmutable $now, bool $raiseAlert): void
    {
        if ($licence->isRevoked()) {
            throw LicenceApiErrors::revoked();
        }

        if ($licence->isBound() && $licence->device_id !== $request->deviceId) {
            if ($raiseAlert) {
                $this->alerts->raise($licence, LicenceAlertType::SameKeyTwoDevices, $request);
                $this->devices->record($licence, $request, DeviceHistory::REJECTED, $now);
            }

            throw LicenceApiErrors::boundToOtherDevice();
        }
    }

    /**
     * @throws ApiException licence.not_activatable
     */
    private function bind(Licence $licence, TillRequest $request, CarbonImmutable $now): void
    {
        $state = LicenceState::for($licence, $now);

        if ($state->status === LicenceStatus::Suspended) {
            throw LicenceApiErrors::notActivatable($state->reason);
        }

        $before = [
            'status' => $licence->status->value,
            'activated_at' => $licence->activated_at?->toIso8601String(),
            'trial_ends_at' => $licence->trial_ends_at?->toIso8601String(),
            'grace_days' => $licence->grace_days,
            'device_id' => $licence->device_id,
            'device_name' => $licence->device_name,
        ];
        $firstActivation = $licence->activated_at === null;

        if ($firstActivation) {
            $this->startTerms($licence, $now);
        }

        $state = LicenceState::for($licence, $now);

        if (! $state->canTrade()) {
            throw LicenceApiErrors::notActivatable($state->reason);
        }

        $licence->device_id = $request->deviceId;
        $licence->device_name = $request->deviceName;
        $licence->bound_at = $now;
        TillAudit::touch($licence, $request, $now);
        $licence->save();

        $companyTrialEndsAt = $firstActivation ? $this->startCompanyTrial($licence) : null;
        $this->devices->record($licence, $request, DeviceHistory::ACTIVATED, $now);

        $this->audit->record($firstActivation ? 'licence.activated' : 'licence.device_bound', $licence, $before, [
            'status' => $licence->status->value,
            'activated_at' => $licence->activated_at?->toIso8601String(),
            'trial_ends_at' => $licence->trial_ends_at?->toIso8601String(),
            'grace_days' => $licence->grace_days,
            'device_id' => $licence->device_id,
            'device_name' => $licence->device_name,
        ], $request, array_filter(['os' => $request->os, 'company_trial_ends_at' => $companyTrialEndsAt]));
    }

    /** First activation: start the trial (or the paid term renewed before activation). */
    private function startTerms(Licence $licence, CarbonImmutable $now): void
    {
        $plan = $licence->plan;
        $licence->activated_at = $now;

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

    /** The bound PC activated again (reinstall): no binding change, only its name and last contact. */
    private function reinstall(Licence $licence, TillRequest $request, CarbonImmutable $now): void
    {
        $before = ['device_name' => $licence->device_name];

        if ($request->deviceName !== null) {
            $licence->device_name = $request->deviceName;
        }

        TillAudit::touch($licence, $request, $now);
        $licence->save();

        $this->devices->record($licence, $request, DeviceHistory::REINSTALLED, $now);
        $this->audit->record('licence.reinstalled', $licence, $before, ['device_name' => $licence->device_name], $request);
    }
}
