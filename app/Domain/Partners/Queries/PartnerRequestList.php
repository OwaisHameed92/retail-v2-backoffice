<?php

namespace App\Domain\Partners\Queries;

use App\Domain\Partners\Enums\PartnerRequestStatus;
use App\Domain\Partners\Models\Partner;
use App\Domain\Partners\Models\PartnerTenantRequest;
use App\Domain\Shared\Support\TableQuery;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Admin → Partner requests: the list (search business, deal id or seller; filter by status, "partner cancelled" and
 * partner; newest first), the counts per status and the filter options.
 */
final class PartnerRequestList
{
    public const PARTNER_CANCELLED = 'partnerCancelled';

    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $statusFilter = (string) $request->string('status');
        $status = PartnerRequestStatus::tryFrom($statusFilter);
        $partnerId = (string) $request->string('partner');
        $table = TableQuery::from($request)
            ->searchable(['business_name', 'external_id', 'seller_name', 'seller_email'])
            ->sortable(['business_name', 'status', 'created_at', 'updated_at'])
            ->defaultSort('created_at', 'desc');

        $query = PartnerTenantRequest::query()->with(['partner:id,name', 'company:id,name,status'])
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status?->value))
            ->when($statusFilter === self::PARTNER_CANCELLED, fn (Builder $q) => $q->where('partner_cancelled_after_approval', true))
            ->when($partnerId !== '', fn (Builder $q) => $q->where('partner_id', $partnerId));

        $counts = PartnerTenantRequest::query()->toBase()->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status');

        return [
            'requests' => $table->paginate($query, fn (PartnerTenantRequest $row) => self::row($row)),
            'filters' => ['status' => $status->value ?? ($statusFilter === self::PARTNER_CANCELLED ? self::PARTNER_CANCELLED : null), 'partner' => $partnerId === '' ? null : $partnerId],
            'statuses' => PartnerRequestStatus::options(),
            'counts' => $counts,
            'partnerCancelledCount' => PartnerTenantRequest::query()->where('partner_cancelled_after_approval', true)->count(),
            'partners' => Partner::query()->orderBy('name')->get(['id', 'name'])->map(fn (Partner $p) => ['value' => $p->id, 'label' => $p->name])->values(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(PartnerTenantRequest $row): array
    {
        return [
            'id' => $row->id,
            'externalId' => $row->external_id,
            'businessName' => $row->business_name,
            'partner' => $row->partner?->name,
            'sellerName' => $row->seller_name,
            'sellerEmail' => $row->seller_email,
            'status' => $row->status->value,
            'partnerCancelled' => $row->partner_cancelled_after_approval,
            'tills' => $row->tills(),
            'shops' => is_array($row->payload['shops'] ?? null) ? count($row->payload['shops']) : 0,
            'plan' => is_string($row->payload['plan'] ?? null) ? $row->payload['plan'] : null,
            'company' => $row->company === null ? null : ['id' => $row->company->id, 'name' => $row->company->name],
            'createdAt' => $row->created_at?->toIso8601String(),
            'updatedAt' => $row->updated_at?->toIso8601String(),
        ];
    }
}
