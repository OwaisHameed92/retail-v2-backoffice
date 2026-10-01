<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Leads\Models\Lead;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;

/**
 * "Recent activity" and the "Business overview" status counts on the admin dashboard (module 7.1 pass 2), from
 * real rows only: tenants created, invoices paid and leads created, newest 5 of each, tagged with the area that
 * may see them (billing / leads / tenants) so AdminDashboardData::forViewer() can drop what an admin may not see.
 *
 * @phpstan-type Activity array{id: string, area: string, kind: string, title: string, detail: string, at: string, href: string}
 * @phpstan-type StatusCounts array{total: int, active: int, trial: int, overdue: int, suspended: int, cancelled: int}
 */
final class RecentActivity
{
    public const LIMIT = 5;

    /**
     * @return list<Activity>
     */
    public static function collect(DashboardRows $rows): array
    {
        $tenants = $rows->companies
            ->filter(fn (Company $company) => $company->deleted_at === null && $company->created_at !== null)
            ->sortByDesc(fn (Company $company) => $company->created_at?->getTimestamp())
            ->take(self::LIMIT)
            ->map(fn (Company $company) => [
                'id' => 'tenant-'.$company->id,
                'area' => 'tenants',
                'kind' => 'tenant',
                'title' => 'New tenant registered',
                'detail' => $company->name,
                'at' => CarbonImmutable::instance($company->created_at ?? $rows->now)->utc()->toIso8601String(),
                'href' => route('admin.tenants.show', $company->id, false),
            ])->values()->all();

        $invoices = Invoice::withoutCompanyScope()
            ->where('status', InvoiceStatus::Paid->value)
            ->whereNotNull('paid_at')
            ->orderByDesc('paid_at')
            ->limit(self::LIMIT)
            ->get(['id', 'company_id', 'number', 'paid_at', 'total'])
            ->map(fn (Invoice $invoice) => [
                'id' => 'invoice-'.$invoice->id,
                'area' => 'billing',
                'kind' => 'invoice',
                'title' => 'Invoice paid',
                'detail' => implode(' · ', array_filter([
                    $invoice->number,
                    BillingFormat::money($invoice->total),
                    $rows->company($invoice->company_id)->name ?? null,
                ])),
                'at' => CarbonImmutable::instance($invoice->paid_at ?? $rows->now)->utc()->toIso8601String(),
                'href' => route('admin.billing.invoices.show', $invoice->id, false),
            ])->all();

        $leads = Lead::query()
            ->whereNotNull('created_at')
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get(['id', 'business_name', 'created_at'])
            ->map(fn (Lead $lead) => [
                'id' => 'lead-'.$lead->id,
                'area' => 'leads',
                'kind' => 'lead',
                'title' => 'New lead created',
                'detail' => $lead->business_name,
                'at' => CarbonImmutable::instance($lead->created_at ?? $rows->now)->utc()->toIso8601String(),
                'href' => route('admin.leads.show', $lead->id, false),
            ])->all();

        return [...$tenants, ...$invoices, ...$leads];
    }

    /**
     * Newest first, capped: the items an admin may see.
     *
     * @param  list<Activity>  $items
     * @param  list<string>  $areas
     * @return list<Activity>
     */
    public static function visible(array $items, array $areas): array
    {
        $visible = array_values(array_filter($items, fn (array $item) => in_array($item['area'], $areas, true)));
        usort($visible, fn (array $a, array $b) => strcmp($b['at'], $a['at']) ?: strcmp($a['id'], $b['id']));

        return array_slice($visible, 0, self::LIMIT);
    }

    /**
     * How many tenants (not deleted) are in each status now.
     *
     * @return StatusCounts
     */
    public static function statusCounts(DashboardRows $rows): array
    {
        $live = $rows->companies->filter(fn (Company $company) => $company->deleted_at === null);
        $count = fn (CompanyStatus $status) => $live->filter(fn (Company $company) => $company->status === $status)->count();

        return [
            'total' => $live->count(),
            'active' => $count(CompanyStatus::Active),
            'trial' => $count(CompanyStatus::Trial),
            'overdue' => $count(CompanyStatus::Overdue),
            'suspended' => $count(CompanyStatus::Suspended),
            'cancelled' => $count(CompanyStatus::Cancelled),
        ];
    }
}
