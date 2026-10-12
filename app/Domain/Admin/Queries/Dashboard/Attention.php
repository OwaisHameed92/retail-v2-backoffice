<?php

namespace App\Domain\Admin\Queries\Dashboard;

use App\Domain\Billing\Enums\InvoiceStatus;
use App\Domain\Billing\Models\Invoice;
use App\Domain\Billing\Support\BillingFormat;
use App\Domain\Leads\Models\Lead;
use App\Domain\Licensing\Enums\LicenceAlertType;
use App\Domain\Licensing\Models\LicenceAlert;
use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Partners\Support\PartnerApi;
use App\Domain\Shared\Country\Country;
use Carbon\CarbonImmutable;

/**
 * "Needs attention": trials ending within 2 days, open licence alerts, overdue invoices, leads whose
 * follow-up is late and partner requests waiting for review (UK, `partners` area: tenants.manage). Each area keeps its newest 5 and its total, so AdminDashboard can drop the areas an admin
 * may not see and still show the newest 5 of the rest. `at` is when the item needed attention (a trial: 2 days
 * before it ends).
 *
 * @phpstan-type Item array{id: string, area: string, label: string, tone: string, text: string, at: string, href: string}
 */
final class Attention
{
    public const LIMIT = 5;

    public const TRIAL_DAYS = 2;

    /**
     * @param  array<string, CarbonImmutable>  $trialEnds  Company id → trial end (Kpis::trialEnds()).
     * @return array{items: list<Item>, totals: array<string, int>}
     */
    public static function collect(DashboardRows $rows, array $trialEnds): array
    {
        $trials = self::trials($rows, $trialEnds);
        $alerts = LicenceAlert::withoutCompanyScope()->open();
        $late = Lead::query()->open()->whereNotNull('follow_up_at')->where('follow_up_at', '<', $rows->now);
        $overdue = $rows->overdue->filter(fn (Invoice $invoice) => $invoice->status === InvoiceStatus::Overdue);
        // One query (joined for the partner's name): waiting requests are few, so all are read and counted here.
        $partners = PartnerApi::enabled()
            ? PartnerTenantRequest::query()->join('partners', 'partners.id', '=', 'partner_tenant_requests.partner_id')
                ->where('partner_tenant_requests.status', PartnerRequestStatus::PendingReview)
                ->orderByDesc('partner_tenant_requests.updated_at')
                ->get(['partner_tenant_requests.id', 'partner_tenant_requests.business_name', 'partner_tenant_requests.updated_at', 'partners.name as partner_name'])
            : collect();

        $items = [
            ...array_slice($trials, 0, self::LIMIT),
            ...(clone $alerts)->orderByDesc('last_seen_at')->limit(self::LIMIT)->get()
                ->map(fn (LicenceAlert $alert) => [
                    'id' => 'alert-'.$alert->id,
                    'area' => 'licences',
                    // Module 2.7: Till health alerts read "Sync" (violet) or "Till" (amber).
                    'label' => match (true) {
                        in_array($alert->type, [LicenceAlertType::SyncFailing, LicenceAlertType::SyncStalled], true) => 'Sync',
                        $alert->type->isAutomatic() => 'Till',
                        in_array($alert->type, [LicenceAlertType::TillsRequested, LicenceAlertType::SubscriptionRequested], true) => 'Request',
                        default => 'Alert',
                    },
                    'tone' => match (true) {
                        in_array($alert->type, [LicenceAlertType::SyncFailing, LicenceAlertType::SyncStalled], true) => 'violet',
                        $alert->type->isAutomatic() => 'warning',
                        $alert->type === LicenceAlertType::TillsRequested => 'info',
                        $alert->type === LicenceAlertType::SubscriptionRequested => 'warning',
                        default => 'danger',
                    },
                    'text' => $alert->type->label().' · '.($rows->company($alert->company_id)->name ?? 'Unknown business'),
                    'at' => $alert->last_seen_at->utc()->toIso8601String(),
                    'href' => route('admin.licences.show', $alert->licence_id, false),
                ])->all(),
            ...$overdue->sortByDesc(fn (Invoice $invoice) => $invoice->overdue_at?->getTimestamp())->take(self::LIMIT)
                ->map(fn (Invoice $invoice) => [
                    'id' => 'invoice-'.$invoice->id,
                    'area' => 'billing',
                    'label' => 'Invoice',
                    'tone' => 'danger',
                    'text' => ($invoice->number ?? 'Invoice').' overdue · '.($rows->company($invoice->company_id)->name ?? 'Unknown business').' · '.BillingFormat::money($invoice->balance),
                    'at' => ($invoice->overdue_at ?? $rows->now)->utc()->toIso8601String(),
                    'href' => route('admin.billing.invoices.show', $invoice->id, false),
                ])->values()->all(),
            ...(clone $late)->orderByDesc('follow_up_at')->limit(self::LIMIT)->get(['id', 'business_name', 'follow_up_at'])
                ->map(fn (Lead $lead) => [
                    'id' => 'lead-'.$lead->id,
                    'area' => 'leads',
                    'label' => 'Lead',
                    'tone' => 'info',
                    'text' => 'Follow-up overdue · '.$lead->business_name,
                    'at' => CarbonImmutable::instance($lead->follow_up_at ?? $rows->now)->utc()->toIso8601String(),
                    'href' => route('admin.leads.show', $lead->id, false),
                ])->all(),
            ...$partners->take(self::LIMIT)
                ->map(fn (PartnerTenantRequest $request) => [
                    'id' => 'partner-request-'.$request->id,
                    'area' => 'partners',
                    'label' => 'Partner',
                    'tone' => 'info',
                    'text' => 'Waiting for review · '.$request->business_name.' · '.($request->getAttribute('partner_name') ?? 'Partner'),
                    'at' => ($request->updated_at ?? $rows->now)->utc()->toIso8601String(),
                    'href' => route('admin.partner-requests.show', $request->id, false),
                ])->values()->all(),
        ];

        return [
            'items' => $items,
            'totals' => [
                'tenants' => count($trials),
                'licences' => $alerts->count(),
                'billing' => $overdue->count(),
                'leads' => $late->count(),
                'partners' => $partners->count(),
            ],
        ];
    }

    /**
     * Newest first, capped: the items an admin may see.
     *
     * @param  list<Item>  $items
     * @param  list<string>  $areas
     * @return list<Item>
     */
    public static function visible(array $items, array $areas): array
    {
        $visible = array_values(array_filter($items, fn (array $item) => in_array($item['area'], $areas, true)));
        usort($visible, fn (array $a, array $b) => strcmp($b['at'], $a['at']) ?: strcmp($a['id'], $b['id']));

        return array_slice($visible, 0, self::LIMIT);
    }

    /**
     * @param  array<string, CarbonImmutable>  $trialEnds
     * @return list<Item>
     */
    private static function trials(DashboardRows $rows, array $trialEnds): array
    {
        $cutoff = $rows->now->addDays(self::TRIAL_DAYS);
        $items = [];

        foreach ($trialEnds as $companyId => $endsAt) {
            if ($endsAt->greaterThan($cutoff)) {
                continue;
            }

            $items[] = [
                'id' => 'trial-'.$companyId,
                'area' => 'tenants',
                'label' => 'Trial',
                'tone' => 'warning',
                'text' => ($rows->company($companyId)->name ?? 'A business').' · trial ends '.$endsAt->setTimezone(Country::zone())->format('D j M, H:i'),
                'at' => $endsAt->subDays(self::TRIAL_DAYS)->utc()->toIso8601String(),
                'href' => route('admin.tenants.show', $companyId, false),
            ];
        }

        usort($items, fn (array $a, array $b) => strcmp($b['at'], $a['at']));

        return $items;
    }
}
