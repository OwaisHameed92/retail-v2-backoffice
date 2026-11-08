<?php

namespace App\Domain\Salon\Calendar;

use App\Domain\Tenancy\Models\Branch;
use App\Domain\TillData\Enums\SalonAppointmentStatus;
use App\Domain\TillData\Models\Product;
use App\Domain\TillData\Models\SalonAppointment;
use App\Domain\TillData\Models\SalonAppointmentService;
use Carbon\CarbonImmutable;

/**
 * The events of one stylist's calendar feed (salon README §12), built only from rows the till already pushes
 * (SalonAppointment, SalonAppointmentService, Product for the service name). Runs inside the business's tenant scope.
 *
 * - One event per appointment service row of that shop where the person is the stylist or the assistant.
 * - Window: services overlapping 7 days back to 60 days ahead.
 * - No-shows are left out. Cancelled appointments stay for 7 days after they were cancelled, as STATUS:CANCELLED,
 *   then drop. A row the till deleted drops at once.
 * - SUMMARY = service name + the client's first name only. Nothing else about the client, no price, no notes.
 */
final class StylistCalendar
{
    public const DAYS_BACK = 7;

    public const DAYS_AHEAD = 60;

    public const CANCELLED_KEPT_DAYS = 7;

    /** A safety cap on one feed (a very busy stylist books far fewer services in 67 days). */
    public const MAX_EVENTS = 3000;

    /**
     * @return list<CalendarEvent>
     */
    public function handle(Branch $branch, string $userId, CarbonImmutable $now): array
    {
        $now = $now->utc();
        $services = SalonAppointmentService::query()
            ->where('branch_id', $branch->id)
            ->where(fn ($query) => $query->where('stylist_user_id', $userId)->orWhere('assistant_user_id', $userId))
            ->where('starts_at', '<', $now->addDays(self::DAYS_AHEAD)->format('Y-m-d H:i:s'))
            ->where('ends_at', '>', $now->subDays(self::DAYS_BACK)->format('Y-m-d H:i:s'))
            ->whereHas('salonAppointment', fn ($query) => $query->where('branch_id', $branch->id)
                ->where(fn ($status) => $status->whereNull('status')->orWhere('status', '!=', SalonAppointmentStatus::NoShow->value)))
            ->with('salonAppointment:id,branch_id,client_name,status,cancelled_at,updated_at')
            ->orderBy('starts_at')->orderBy('id')
            ->limit(self::MAX_EVENTS)
            ->get(['id', 'branch_id', 'appointment_id', 'product_id', 'stylist_user_id', 'assistant_user_id', 'starts_at', 'ends_at', 'updated_at']);

        $names = Product::query()->withTrashed()->whereIn('id', $services->pluck('product_id')->filter()->unique()->values()->all())->pluck('name', 'id');
        $cancelledSince = $now->subDays(self::CANCELLED_KEPT_DAYS);
        $events = [];

        foreach ($services as $service) {
            $appointment = $service->salonAppointment;

            if (! $appointment instanceof SalonAppointment) {
                continue;
            }

            $cancelled = $appointment->status === SalonAppointmentStatus::Cancelled;

            if ($cancelled && ($appointment->cancelled_at ?? $appointment->updated_at ?? $now)->lessThan($cancelledSince)) {
                continue;
            }

            $assisting = $service->stylist_user_id !== $userId;
            $events[] = new CalendarEvent(
                uid: $service->id.'@sspos',
                startsAt: $service->starts_at,
                endsAt: $service->ends_at->greaterThan($service->starts_at) ? $service->ends_at : $service->starts_at->addMinutes(15),
                summary: self::summary((string) ($names[$service->product_id] ?? ''), (string) $appointment->client_name),
                cancelled: $cancelled,
                lastModified: self::later($service->updated_at, $appointment->updated_at),
                description: $assisting ? 'You are assisting on this service.' : null,
                location: $branch->name,
            );
        }

        return $events;
    }

    /** "Cut and blow-dry – Sarah": the service and the client's first name only. */
    public static function summary(string $service, string $clientName): string
    {
        $service = trim($service) !== '' ? trim($service) : 'Appointment';
        $first = self::firstName($clientName);

        return $first === '' ? $service : "{$service} – {$first}";
    }

    /** The first word of the client's name (never the surname), at most 40 characters. */
    public static function firstName(string $clientName): string
    {
        $words = preg_split('/\s+/u', trim($clientName), 2, PREG_SPLIT_NO_EMPTY);

        return $words === false || $words === [] ? '' : mb_substr($words[0], 0, 40);
    }

    private static function later(?CarbonImmutable $a, ?CarbonImmutable $b): ?CarbonImmutable
    {
        if ($a === null || $b === null) {
            return $a ?? $b;
        }

        return $a->greaterThan($b) ? $a : $b;
    }
}
