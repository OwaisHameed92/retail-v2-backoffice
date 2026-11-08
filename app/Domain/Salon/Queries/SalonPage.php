<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Support\SalonShops;
use App\Domain\Shared\Country\Country;
use App\Domain\Shared\Support\ApiDate;
use App\Domain\Shared\Support\TableQuery;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\SalonAppointmentStatus;
use App\Domain\TillData\Enums\SalonOnlineBookingStatus;
use App\Domain\TillData\Models\SalonAppointment;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/**
 * Props of the tenant "Salon" page (`app/salon/index`, W3b): the salon shops in view (the top-bar shop, else every
 * open salon shop; a one-shop user only theirs), their stylists with calendar feed switches, today's appointments,
 * and the online booking requests, read only (the till decides them): status, time, client first name, reference.
 * Never a phone, e-mail, note, answer, deposit or token.
 *
 * `onlineBooking` is the slot for the booking page status (heartbeat, booking and queue links) that W3a fills.
 */
final class SalonPage
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array<string, mixed>
     */
    public function handle(Request $request, ?Branch $current, CarbonImmutable $now): array
    {
        $company = $this->tenancy->require();
        $restricted = $this->tenancy->restrictedBranchId();
        $shops = Branch::query()->active()
            ->when($restricted !== null, fn ($query) => $query->whereKey($restricted))
            ->when($current !== null, fn ($query) => $query->whereKey($current?->id))
            ->orderBy('name')->get(['id', 'name', 'code', 'company_id'])
            ->filter(fn (Branch $shop) => SalonShops::isSalonBranch($company->id, $shop->id))
            ->values();
        $ids = $shops->pluck('id')->all();
        $stylists = SalonStylists::byBranch($ids);
        $today = $this->todayCounts($ids, $now);
        $status = SalonOnlineBookingStatus::tryFrom((string) $request->query('status'))?->value;
        $names = $shops->pluck('name', 'id');

        $requests = TableQuery::from($request)
            ->searchable(['reference', 'client_first_name'])
            ->sortable(['requested_starts_at', 'created_at'])
            ->defaultSort('created_at', 'desc')
            ->paginate(
                SalonOnlineBooking::query()->whereIn('branch_id', $ids)->when($status !== null, fn ($query) => $query->where('status', $status)),
                fn (SalonOnlineBooking $booking) => [
                    'id' => $booking->id,
                    'reference' => (string) $booking->reference,
                    'kind' => $booking->kind?->value,
                    'status' => ($booking->status ?? SalonOnlineBookingStatus::Pending)->value,
                    'requestedStartsAt' => ApiDate::format($booking->getAttributes()['requested_starts_at'] ?? null),
                    'clientFirstName' => trim((string) $booking->client_first_name),
                    'shopName' => $names[$booking->branch_id] ?? null,
                    'createdAt' => $booking->created_at?->toIso8601ZuluString(),
                    'decidedAt' => $booking->decided_at?->toIso8601ZuluString(),
                ],
            );

        $people = collect($stylists)->flatten(1);

        return [
            'shops' => $shops->map(fn (Branch $shop) => [
                'id' => $shop->id,
                'name' => $shop->name,
                'code' => $shop->code,
                'todayAppointments' => $today[$shop->id] ?? 0,
                'stylists' => $stylists[$shop->id] ?? [],
            ])->values()->all(),
            'stats' => [
                'stylists' => $people->where('onTill', true)->count(),
                'feedsOn' => $people->filter(fn (array $p) => ($p['feed']['on'] ?? false) === true)->count(),
                'todayAppointments' => array_sum($today),
                'pendingRequests' => SalonOnlineBooking::query()->whereIn('branch_id', $ids)->where('status', SalonOnlineBookingStatus::Pending->value)->count(),
            ],
            'requests' => $requests,
            'requestFilters' => ['status' => $status],
            'requestStatuses' => array_map(fn (SalonOnlineBookingStatus $s) => $s->value, SalonOnlineBookingStatus::cases()),
            'shopFilter' => $current !== null && $shops->isEmpty() ? $current->name : null,
            // W3a: the booking page status (last heartbeat, accepting, booking / queue / manage links) goes here.
            'onlineBooking' => null,
        ];
    }

    /**
     * Today's appointments (Europe/London day) per shop, not cancelled and not no-shows.
     *
     * @param  list<string>  $ids
     * @return array<string, int>
     */
    private function todayCounts(array $ids, CarbonImmutable $now): array
    {
        $day = $now->setTimezone(app(Country::class)->timezone())->startOfDay();

        return SalonAppointment::query()->whereIn('branch_id', $ids)
            ->where('starts_at', '>=', $day->utc()->format('Y-m-d H:i:s'))
            ->where('starts_at', '<', $day->addDay()->utc()->format('Y-m-d H:i:s'))
            ->where(fn ($query) => $query->whereNull('status')->orWhereNotIn('status', [SalonAppointmentStatus::Cancelled->value, SalonAppointmentStatus::NoShow->value]))
            ->selectRaw('branch_id, COUNT(*) as total')->groupBy('branch_id')
            ->pluck('total', 'branch_id')->map(fn ($n) => (int) $n)->all();
    }
}
