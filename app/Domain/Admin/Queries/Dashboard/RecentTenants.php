<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Licensing\Enums\LicenceStatus;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Licensing\Support\DefaultPlan;
use App\Domain\Shared\Models\AuditLog;
use App\Domain\Shared\Support\Money;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;

/**
 * The 5 most recently active tenants (a new tenant counts as active when created). Last activity is the latest
 * of a till validating its licence, an invoice changing and an audit entry for the company. MRR is the monthly
 * plan price of each paying till (live licence in status active or grace); trial tills add nothing.
 *
 * @phpstan-type TenantRow array{id: string, name: string, plan: string|null, tills: int, mrr: string, lastActivityAt: string|null, status: string, statusLabel: string, href: string}
 */
final class RecentTenants
{
    public const LIMIT = 5;

    /**
     * @return list<TenantRow>
     */
    public static function collect(DashboardRows $rows): array
    {
        $activity = self::lastActivity($rows);
        $companies = $rows->companies
            ->filter(fn (Company $company) => $company->deleted_at === null)
            ->sortByDesc(fn (Company $company) => max(
                $company->created_at?->getTimestamp() ?? 0,
                $activity[$company->id]?->getTimestamp() ?? 0,
            ))
            ->take(self::LIMIT);
        $defaultPlan = null;
        $out = [];

        foreach ($companies as $company) {
            if ($company->plan_id === null) {
                $defaultPlan ??= DefaultPlan::portal()->name ?? '';
            }

            /** @var Collection<int, Licence> $live */
            $live = $rows->licences->filter(fn (Licence $licence) => $licence->company_id === $company->id
                && $licence->live_register_id !== null && $licence->deleted_at === null);
            $paying = $live->filter(fn (Licence $licence) => in_array($licence->status, [LicenceStatus::Active, LicenceStatus::Grace], true));

            $out[] = [
                'id' => $company->id,
                'name' => $company->name,
                'plan' => ($company->plan_id !== null ? $rows->plans->get($company->plan_id)?->name : $defaultPlan) ?: null,
                'tills' => $live->count(),
                'mrr' => BillingFormat::money(Money::sum($paying->map(fn (Licence $licence) => $rows->plans->get($licence->plan_id)->price_per_till_monthly ?? '0'))),
                'lastActivityAt' => $activity[$company->id]?->toIso8601String(),
                'status' => $company->status->value,
                'statusLabel' => $company->status->label(),
                'href' => route('admin.tenants.show', $company->id, false),
            ];
        }

        return $out;
    }

    /**
     * @return array<string, CarbonImmutable|null>
     */
    private static function lastActivity(DashboardRows $rows): array
    {
        $latest = $rows->companies->map(fn () => null)->all();
        $bump = function (string $companyId, mixed $moment) use (&$latest): void {
            if ($moment === null || ! array_key_exists($companyId, $latest)) {
                return;
            }

            $at = CarbonImmutable::parse($moment, 'UTC')->utc();
            $latest[$companyId] = $latest[$companyId] === null ? $at : $latest[$companyId]->max($at);
        };

        foreach ($rows->licences as $licence) {
            $bump($licence->company_id, $licence->last_validated_at);
        }

        Invoice::withoutCompanyScope()->toBase()->selectRaw('company_id, max(updated_at) as at')->groupBy('company_id')->get()
            ->each(fn (\stdClass $row) => $bump((string) $row->company_id, $row->at));

        AuditLog::query()->toBase()->whereNotNull('company_id')->selectRaw('company_id, max(created_at) as at')->groupBy('company_id')->get()
            ->each(fn (\stdClass $row) => $bump((string) $row->company_id, $row->at));

        return $latest;
    }
}
