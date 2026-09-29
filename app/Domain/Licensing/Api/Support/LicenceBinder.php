<?php

namespace App\Domain\Licensing\Api\Support;

use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\BranchLicenceTerm;
use App\Domain\Licensing\Support\LicenceTerms;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Carbon;

/**
 * Binds a licence to the PC (install) that activated it, or records a reinstall of the bound PC (contract v1.4.1
 * §17.15.1 steps 2–4a). Shared by `licence/activate`, a portal key redeemed at a linked till (`licence/redeem`) and
 * `cloud/migrate` (module 2.8). Call inside the caller's transaction, on a licence it has locked; the caller saves
 * the token.
 */
final class LicenceBinder
{
    public function __construct(
        private readonly DeviceHistory $devices,
        private readonly TillAudit $audit,
    ) {}

    /**
     * @throws ApiException licence.seat_limit
     */
    public function bind(Licence $licence, TillRequest $till, CarbonImmutable $now): void
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
    public function reinstall(Licence $licence, TillRequest $till, CarbonImmutable $now): void
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
