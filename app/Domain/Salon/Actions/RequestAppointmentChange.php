<?php

namespace App\Domain\Salon\Actions;

use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Availability\StylistChoice;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\BookingTimes;
use App\Domain\Salon\Online\OnlineRequestWriter;
use App\Domain\Salon\Online\SalonShopStatus;
use App\Domain\Salon\Queries\ManagedAppointment;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\SalonOnlineBookingKind;
use App\Domain\TillData\Models\SalonAppointment;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Carbon\CarbonImmutable;
use Illuminate\Validation\ValidationException;

/**
 * A change sent from a manage link (W3a, salon pack README §8): a **new** request naming the appointment, never a
 * change to the appointment itself (the till is the diary of record).
 *
 * - `reschedule`: a new time from the page's free times (§10) for the appointment's services; until
 *   `changeWindowHours` before the start, else "Please ring the shop".
 * - `cancel`: the same window.
 * - `preferences`: the three marketing ticks as the client left them, any time, for an appointment that names a
 *   customer; the till takes it at once (a tick gives consent, an unticked box withdraws one that stood).
 *
 * The client's name and contact come from the appointment as the till pushed it. A closed page takes no move or
 * cancel (ring the shop); preferences are always taken.
 */
final class RequestAppointmentChange
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @param  array{stylist?: string, date?: string, time?: string, sms?: bool, email?: bool, whatsApp?: bool, clientKey: string}  $data
     *
     * @throws ValidationException
     */
    public function handle(SalonOnlineShop $shop, SalonAppointment $appointment, SalonOnlineBookingKind $kind, array $data): SalonOnlineBooking
    {
        return $this->tenancy->runAs($shop->company()->firstOrFail(), fn (): SalonOnlineBooking => $this->write($shop, $appointment, $kind, $data));
    }

    /**
     * @param  array{stylist?: string, date?: string, time?: string, sms?: bool, email?: bool, whatsApp?: bool, clientKey: string}  $data
     */
    private function write(SalonOnlineShop $shop, SalonAppointment $appointment, SalonOnlineBookingKind $kind, array $data): SalonOnlineBooking
    {
        $now = CarbonImmutable::now('UTC');
        $sent = OnlineRequestWriter::find($shop, $data['clientKey']);

        if ($sent !== null) {
            return $sent;   // the same form again: one request
        }

        $attributes = match ($kind) {
            SalonOnlineBookingKind::Preferences => $this->preferences($appointment, $data),
            SalonOnlineBookingKind::Reschedule => $this->move($shop, $appointment, $data, $now),
            SalonOnlineBookingKind::Cancel => $this->cancel($shop, $appointment, $now),
            default => throw ValidationException::withMessages(['kind' => 'This change cannot be made online.']),
        };

        [$first, $last] = array_pad(explode(' ', trim((string) $appointment->client_name), 2), 2, '');

        return OnlineRequestWriter::write($shop, [
            'kind' => $kind,
            'appointment_id' => $appointment->id,
            'customer_id' => $appointment->customer_id,
            'requested_starts_at' => $appointment->starts_at,
            'requested_user_id' => null,
            'client_first_name' => mb_substr($first, 0, 100),
            'client_last_name' => mb_substr($last, 0, 100),
            'client_phone' => (string) $appointment->client_phone,
            'client_email' => (string) $appointment->client_email,
            ...$attributes,
        ], $data['clientKey'], 'manage');
    }

    /**
     * @param  array{sms?: bool, email?: bool, whatsApp?: bool}  $data
     * @return array<string, mixed>
     */
    private function preferences(SalonAppointment $appointment, array $data): array
    {
        if ($appointment->customer_id === null) {
            throw ValidationException::withMessages(['sms' => 'Your marketing choices cannot be changed online for this booking. Please ring the shop.']);
        }

        return [
            'marketing_sms' => (bool) ($data['sms'] ?? false),
            'marketing_email' => (bool) ($data['email'] ?? false),
            'marketing_whats_app' => (bool) ($data['whatsApp'] ?? false),
            'expires_at' => null,
        ];
    }

    /**
     * @param  array{stylist?: string, date?: string, time?: string}  $data
     * @return array<string, mixed>
     */
    private function move(SalonOnlineShop $shop, SalonAppointment $appointment, array $data, CarbonImmutable $now): array
    {
        $this->inWindow($shop, $appointment, $now);

        $services = ManagedAppointment::services($appointment);
        $basket = (new PageMenu((array) $shop->page))->basketOf(array_column($services, 'productId'))
            ?? throw ValidationException::withMessages(['time' => 'This appointment cannot be moved online. Please ring the shop.']);
        $stylist = (string) ($data['stylist'] ?? StylistChoice::ANY);

        $start = BookingTimes::check($shop, $basket, $stylist, (string) ($data['date'] ?? ''), (string) ($data['time'] ?? ''), $now)
            ?? throw ValidationException::withMessages(['time' => 'That time has just gone. Choose another time.']);

        return [
            'requested_starts_at' => $start,
            'requested_user_id' => StylistChoice::requestedUser($stylist),
            'services_json' => (string) json_encode(array_map(fn (array $s) => ['productId' => $s['productId']], $services), JSON_UNESCAPED_SLASHES),
            'notes' => StylistChoice::wish($stylist),
            'expires_at' => $now->addMinutes(max(1, (int) ($shop->page['rules']['holdMin'] ?? 30))),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function cancel(SalonOnlineShop $shop, SalonAppointment $appointment, CarbonImmutable $now): array
    {
        $this->inWindow($shop, $appointment, $now);

        return ['expires_at' => $now->addMinutes(max(1, (int) ($shop->page['rules']['holdMin'] ?? 30)))];
    }

    private function inWindow(SalonOnlineShop $shop, SalonAppointment $appointment, CarbonImmutable $now): void
    {
        if (SalonShopStatus::booking($shop, $now) === SalonShopStatus::CLOSED || ! ManagedAppointment::changeable($shop, $appointment, $now)) {
            throw ValidationException::withMessages(['time' => 'This appointment can no longer be changed online. Please ring the shop.']);
        }
    }
}
