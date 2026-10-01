<?php

namespace App\Domain\Privacy\Queries;

use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Enums\DataRequestType;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Privacy\Models\PrivacySettings;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\TillData\Models\Customer;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * Props for the tenant Privacy screen (module 7.7, owner only): data requests (newest first, filter by type and
 * status), the retention setting with how many customers are past it, and a sample of them. Runs in the company
 * scope.
 */
final class PrivacyPage
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $type = DataRequestType::tryFrom((string) $request->query('type'));
        $status = DataRequestStatus::tryFrom((string) $request->query('status'));
        $settings = PrivacySettings::current();
        $months = $settings->retention_months;

        $query = DataRequest::query()->with('requestedBy:id,name')
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status));
        $requests = TableQuery::from($request)->sortable(['created_at'])->defaultSort('created_at', 'desc')->defaultPerPage(25)
            ->paginate($query, fn (DataRequest $r) => self::row($r));

        return [
            'requests' => $requests,
            'filters' => ['type' => $type?->value, 'status' => $status?->value],
            'settings' => [
                'retentionMonths' => $months,
                'autoAnonymise' => (bool) $settings->auto_anonymise,
                'dueCount' => $months === null ? 0 : RetentionDue::query($months)->count(),
                'lastCheckedAt' => $settings->last_checked_at?->toIso8601ZuluString(),
                'cutoff' => $months === null ? null : RetentionDue::cutoff($months)->toIso8601ZuluString(),
                'minMonths' => PrivacySettings::MIN_MONTHS,
                'maxMonths' => PrivacySettings::MAX_MONTHS,
            ],
            'due' => $months === null ? [] : RetentionDue::sample($months),
            'pendingCount' => DataRequest::query()->where('status', DataRequestStatus::TillPending)->count(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function row(DataRequest $r): array
    {
        $customer = $r->customer_id === null ? null : Customer::query()->withTrashed()->find($r->customer_id);

        return [
            'id' => $r->id,
            'type' => $r->type->value,
            'typeLabel' => $r->type->label(),
            'status' => $r->status->value,
            'statusLabel' => $r->status->label(),
            'source' => $r->source,
            'customerId' => $r->customer_id,
            'customerName' => $customer === null ? 'Customer no longer held' : (string) $customer->name,
            'customerExists' => $customer !== null && ! $customer->trashed(),
            'requestedBy' => $r->source === 'retention' ? 'Data retention' : ($r->requestedBy->name ?? 'Someone who has left'),
            'tillSteps' => $r->till_steps ?? [],
            'note' => $r->note,
            'createdAt' => $r->created_at?->toIso8601ZuluString(),
            'completedAt' => $r->completed_at?->toIso8601ZuluString(),
        ];
    }

    /**
     * A customer's requests for the customer page's Privacy tab, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public static function forCustomer(string $customerId): array
    {
        return DataRequest::query()->with('requestedBy:id,name')->where('customer_id', $customerId)->latest('created_at')->limit(20)->get()
            ->map(fn (DataRequest $r) => self::row($r))->values()->all();
    }
}
