<?php

namespace App\Domain\Privacy\Queries;

use App\Domain\Privacy\Enums\DataRequestStatus;
use App\Domain\Privacy\Enums\DataRequestType;
use App\Domain\Privacy\Models\DataRequest;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\Models\Company;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;

/**
 * The admin "Data requests" list (module 7.7): every business's customer data requests, newest first, filtered by
 * type, status and business name. Shows no customer details (support does not need them): the business, the kind of
 * request, its status and the till steps still open.
 */
final class AdminDataRequests
{
    /**
     * @return array<string, mixed>
     */
    public static function for(Request $request): array
    {
        $type = DataRequestType::tryFrom((string) $request->query('type'));
        $status = DataRequestStatus::tryFrom((string) $request->query('status'));

        $table = TableQuery::from($request)->sortable(['created_at'])->defaultSort('created_at', 'desc')->defaultPerPage(25);
        $search = $table->search();
        $query = DataRequest::withoutCompanyScope()->with('company:id,name')
            ->when($type !== null, fn (Builder $q) => $q->where('type', $type))
            ->when($status !== null, fn (Builder $q) => $q->where('status', $status))
            ->when($search !== null, fn (Builder $q) => $q->whereIn('company_id', Company::query()->where('name', 'like', '%'.$search.'%')->select('id')));

        $requests = $table->paginate($query, fn (DataRequest $r) => [
            'id' => $r->id,
            'companyId' => $r->company_id,
            'company' => (string) ($r->company->name ?? 'Deleted business'),
            'type' => $r->type->value,
            'typeLabel' => $r->type->label(),
            'status' => $r->status->value,
            'statusLabel' => $r->status->label(),
            'source' => $r->source,
            'tillSteps' => count($r->till_steps ?? []),
            'createdAt' => $r->created_at?->toIso8601ZuluString(),
            'completedAt' => $r->completed_at?->toIso8601ZuluString(),
        ]);

        return [
            'requests' => $requests,
            'filters' => ['type' => $type?->value, 'status' => $status?->value],
            'counts' => [
                'total' => DataRequest::withoutCompanyScope()->count(),
                'tillPending' => DataRequest::withoutCompanyScope()->where('status', DataRequestStatus::TillPending)->count(),
                'last30Days' => DataRequest::withoutCompanyScope()->where('created_at', '>=', now('UTC')->subDays(30))->count(),
            ],
        ];
    }
}
