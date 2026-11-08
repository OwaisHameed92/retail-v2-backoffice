<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Salon\Availability\LondonClock;
use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\ManageLinkToken;
use App\Domain\Salon\Online\SalonShopStatus;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Models\SalonAppointment;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Carbon\CarbonImmutable;

/**
 * The "request sent" page (W3a, salon pack README §2, §6): where the client's request is, read again by the page every
 * few seconds. `pending` until the till answers (never "booked" before then); `accepted` with the time the till booked
 * and the manage link; `declined` with the till's reason as it wrote it; `cancelled` once withdrawn. An accept after
 * `expiresAt` still counts. Only the request's own details, of this shop only; no phone or e-mail.
 */
final class BookingRequestView
{
    public function __construct(private readonly CurrentCompany $tenancy) {}

    /**
     * @return array<string, mixed>|null null when this shop has no such request
     */
    public function handle(SalonOnlineShop $shop, string $requestId): ?array
    {
        return $this->tenancy->runAs($shop->company()->firstOrFail(), function () use ($shop, $requestId): ?array {
            $booking = SalonOnlineBooking::query()->where('branch_id', $shop->branch_id)->where('kind', 'new')->find($requestId);

            if ($booking === null) {
                return null;
            }

            $now = CarbonImmutable::now('UTC');
            $menu = new PageMenu((array) $shop->page);
            $appointment = $booking->appointment_id === null ? null : SalonAppointment::query()->where('branch_id', $shop->branch_id)->find($booking->appointment_id);
            $start = $appointment === null ? $booking->requested_starts_at : $appointment->starts_at;
            $stylists = $menu->stylists();
            $products = array_map(fn ($line) => is_array($line) ? (string) ($line['productId'] ?? '') : '', (array) json_decode((string) $booking->services_json, true));

            return [
                'reference' => $booking->reference,
                'status' => $booking->status?->value,
                'when' => LondonClock::label($start, 'l j F Y'),
                'time' => LondonClock::label($start, 'H:i'),
                'stylist' => $booking->requested_user_id === null ? null : ($stylists[$booking->requested_user_id]['firstName'] ?? null),
                'services' => array_values(array_filter(array_map(fn (string $id) => self::name($menu, $id), $products))),
                'firstName' => (string) $booking->client_first_name,
                'reason' => (string) $booking->decision_reason,
                'autoAccept' => (bool) ($menu->rule('autoAccept') ?? false),
                'offline' => SalonShopStatus::offline($shop, $now),
                'overdue' => $booking->expires_at !== null && $booking->expires_at->lessThan($now),
                'manageUrl' => $booking->status?->value === 'accepted' && $booking->appointment_id !== null ? ManageLinkToken::link($shop, $booking->appointment_id) : null,
            ];
        });
    }

    private static function name(PageMenu $menu, string $productId): ?string
    {
        $option = $menu->option($productId);

        if ($option !== null) {
            return trim($option['service']['name'].($option['option']['label'] !== '' ? ' – '.$option['option']['label'] : ''));
        }

        return $menu->addOn($productId)['addOn']['name'] ?? null;
    }
}
