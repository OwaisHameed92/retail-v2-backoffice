<?php

namespace App\Domain\TillHealth\Actions;

use App\Domain\Calendar\Queries\ShopHours;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Shared\Country\TillProfile;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\TillHealth\Data\BranchHealthRow;
use App\Domain\TillHealth\Data\TillHealthRow;
use App\Domain\TillHealth\Enums\HealthProblem;
use App\Domain\TillHealth\Support\HealthThresholds;
use App\Domain\TillHealth\Support\TradingHours;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Log;

/**
 * Raises and clears the Till health alerts (module 2.7) with the licence alert mechanism: one open
 * `licence_alerts` row per licence and problem (TillOffline, SyncFailing, SyncStalled, AppVersionOutdated,
 * ClockSkew). Only tills that should be trading are watched (licence trial/active/grace, business not suspended
 * or cancelled). "Till offline" is raised only during the shop's trading hours (its opening hours and special days,
 * module 5.9; else the default), after `alert_offline_hours` trading hours of
 * silence; once open it stays until the till is back. Every other open health alert of the chunk whose problem
 * is gone is resolved (no `resolved_by`: the system cleared it). In-app only: no alert email exists yet.
 */
class SyncTillHealthAlerts
{
    /**
     * @param  list<string>  $companyIds
     * @param  array{branches: list<BranchHealthRow>, tills: list<TillHealthRow>}  $health
     * @return array{raised: int, resolved: int}
     */
    public function handle(array $companyIds, array $health, CarbonImmutable $now, HealthThresholds $thresholds): array
    {
        $types = array_values(array_filter(LicenceAlertType::cases(), fn (LicenceAlertType $type) => $type->isAutomatic()));
        $open = LicenceAlert::withoutCompanyScope()->whereIn('company_id', $companyIds)->open()
            ->whereIn('type', array_map(fn (LicenceAlertType $type) => $type->value, $types))
            ->get()
            ->keyBy(fn (LicenceAlert $alert) => $alert->licence_id.'|'.$alert->type->value);

        $branches = [];
        foreach ($health['branches'] as $branch) {
            $branches[$branch->branchId] = $branch;
        }

        $trading = $this->tradingHours($health['tills'], $now, $thresholds);
        $wanted = [];

        foreach ($health['tills'] as $row) {
            if (! $this->watched($row)) {
                continue;
            }

            foreach ($row->problems as $problem) {
                $key = $row->till->licenceId.'|'.$problem->alertType()->value;

                if ($problem === HealthProblem::Offline && ! $open->has($key) && ! $this->offlineLongEnough($row, $trading($row->till->branchId), $now, $thresholds)) {
                    continue;
                }

                $wanted[$key] = [$row, $problem];
            }
        }

        $raised = 0;
        foreach ($wanted as $key => [$row, $problem]) {
            if (! $open->has($key)) {
                $this->raise($row, $problem, $branches[$row->till->branchId] ?? null, $thresholds, $now);
                $raised++;
            }
        }

        $still = $open->filter(fn (LicenceAlert $alert, string $key) => isset($wanted[$key]))->modelKeys();
        $gone = $open->reject(fn (LicenceAlert $alert, string $key) => isset($wanted[$key]))->modelKeys();

        if ($still !== []) {
            LicenceAlert::withoutCompanyScope()->whereIn('id', $still)->update(['last_seen_at' => $now, 'updated_at' => $now]);
        }

        if ($gone !== []) {
            LicenceAlert::withoutCompanyScope()->whereIn('id', $gone)->update(['resolved_at' => $now, 'updated_at' => $now]);
        }

        return ['raised' => $raised, 'resolved' => count($gone)];
    }

    /**
     * Each shop's trading hours (module 5.9: its opening hours and the till's special days; else the default).
     *
     * @param  list<TillHealthRow>  $tills
     * @return callable(string|null): TradingHours
     */
    private function tradingHours(array $tills, CarbonImmutable $now, HealthThresholds $thresholds): callable
    {
        $default = new TradingHours($thresholds);
        $branchIds = array_values(array_filter(array_map(fn (TillHealthRow $row) => $row->till->branchId, $tills)));
        $hours = ShopHours::forBranches($branchIds, $now->subDays(16), $now->addDay(), TradingHours::defaultHours($thresholds));
        $byBranch = array_map(fn ($week) => new TradingHours($thresholds, $week), $hours);

        return fn (?string $branchId) => $byBranch[(string) $branchId] ?? $default;
    }

    private function watched(TillHealthRow $row): bool
    {
        $licence = LicenceStatus::tryFrom((string) $row->till->licenceStatus);
        $company = CompanyStatus::tryFrom($row->till->companyStatus);

        return $row->till->isActivated()
            && $licence !== null && $licence->canTrade()
            && ! in_array($company, [CompanyStatus::Suspended, CompanyStatus::Cancelled, null], true);
    }

    private function offlineLongEnough(TillHealthRow $row, TradingHours $trading, CarbonImmutable $now, HealthThresholds $thresholds): bool
    {
        return $row->lastSeenAt !== null
            && $trading->isOpen($now)
            && $trading->hoursBetween($row->lastSeenAt, $now) >= $thresholds->alertOfflineHours;
    }

    private function raise(TillHealthRow $row, HealthProblem $problem, ?BranchHealthRow $branch, HealthThresholds $thresholds, CarbonImmutable $now): void
    {
        $type = $problem->alertType();
        $licenceId = (string) $row->till->licenceId;

        LicenceAlert::withoutCompanyScope()->create([
            'company_id' => $row->till->companyId,
            'licence_id' => $licenceId,
            'type' => $type,
            'fingerprint' => hash('sha256', 'till-health|'.$type->value),
            'details' => array_filter([
                'summary' => $this->summary($row, $problem, $branch, $thresholds),
                'deviceName' => $row->till->deviceName,
                'appVersion' => $row->appVersion,
            ], fn ($value) => $value !== null),
            'first_seen_at' => $now,
            'last_seen_at' => $now,
            'count' => 1,
        ]);

        Log::warning('Till health alert raised.', ['alert' => $type->value, 'licenceId' => $licenceId, 'companyId' => $row->till->companyId]);
    }

    private function summary(TillHealthRow $row, HealthProblem $problem, ?BranchHealthRow $branch, HealthThresholds $thresholds): string
    {
        $when = fn (?CarbonImmutable $at) => $at === null ? 'never' : $at->setTimezone($thresholds->timezone)->format('j M Y, H:i');
        $skew = (int) $row->till->clockSkewSeconds;

        return match ($problem) {
            HealthProblem::Offline => 'Last heard from '.$when($row->lastSeenAt).'.',
            HealthProblem::OldVersion => TillProfile::appName().' '.$row->appVersion.' (minimum '.$thresholds->minimumAppVersion.').',
            HealthProblem::SyncFailing => mb_substr(trim(($branch->sync->lastErrorCode ?? 'error').': '.($branch->sync->lastErrorMessage ?? '')), 0, 300),
            HealthProblem::SyncStalled => 'Last sync '.$when($branch?->sync?->lastContactAt()).($row->pendingSyncRows !== null ? ', '.$row->pendingSyncRows.' rows waiting on the till.' : '.'),
            HealthProblem::ClockSkew => 'Till clock '.abs($skew).' s '.($skew > 0 ? 'fast' : 'slow').' at the last check-in.',
        };
    }
}
