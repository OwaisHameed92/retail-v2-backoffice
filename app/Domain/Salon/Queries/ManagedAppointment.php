<?php

namespace App\Domain\Salon\Queries;

use App\Domain\Customers\Support\MarketingConsent;
use App\Domain\Salon\Availability\LondonClock;
use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Models\SalonOnlineShop;
use App\Domain\Salon\Online\ManageLinkToken;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\TillData\Enums\SalonAppointmentStatus;
use App\Domain\TillData\Models\SalonAppointment;
use App\Domain\TillData\Models\SalonAppointmentService;
use App\Domain\TillData\Models\SalonOnlineBooking;
use Carbon\CarbonImmutable;

/**
 * What a manage link opens (W3a, salon pack README §8): the appointment as the till pushed it (`SalonAppointment` +
 * its services) of the link's shop only, after the token was checked; whether it can still be moved or cancelled
 * online (until `changeWindowHours` before the start, while booked or confirmed); the client's current marketing
 * consent (newest Consent row per channel, when the appointment names a customer); and the requests already sent
 * from the link. Never the client's phone, e-mail or notes. Runs in the shop's company.
 */
final class ManagedAppointment
{
    public const CHANGEABLE = [SalonAppointmentStatus::Booked, SalonAppointmentStatus::Confirmed];

    /**
     * find() and props() for a public page, in the shop's company.
     *
     * @return array{model: SalonAppointment, props: array<string, mixed>}|null
     */
    public static function open(SalonOnlineShop $shop, string $link, CarbonImmutable $now): ?array
    {
        return app(CurrentCompany::class)->runAs($shop->company()->firstOrFail(), function () use ($shop, $link, $now): ?array {
            $appointment = self::find($shop, $link);

            return $appointment === null ? null : ['model' => $appointment, 'props' => self::props($shop, $appointment, $now)];
        });
    }

    /**
     * The appointment's services as a basket of the page (null when one is no longer on it). In the shop's company.
     *
     * @return list<array{productId: string, kind: string, grid: string, name: string, minutes: array<string, int>|int, prices: array<string, string>|string, fallbackPrice: string, needsPatchTest: bool}>|null
     */
    public static function basket(SalonOnlineShop $shop, SalonAppointment $appointment): ?array
    {
        $services = app(CurrentCompany::class)->runAs($shop->company()->firstOrFail(), fn () => self::services($appointment));

        return (new PageMenu((array) $shop->page))->basketOf(array_column($services, 'productId'));
    }

    /** The appointment of this shop the link names, when its token is right; else null (the page says not found). */
    public static function find(SalonOnlineShop $shop, string $link): ?SalonAppointment
    {
        if (preg_match('/^([0-9A-Za-z]{26})\.([A-Za-z0-9_-]{22})$/', $link, $m) !== 1 || ! ManageLinkToken::valid($shop, $m[1], $m[2])) {
            return null;
        }

        return SalonAppointment::query()->where('branch_id', $shop->branch_id)->find($m[1]);
    }

    public static function changeable(SalonOnlineShop $shop, SalonAppointment $appointment, CarbonImmutable $now): bool
    {
        $hours = (int) ($shop->page['rules']['changeWindowHours'] ?? 24);

        return in_array($appointment->status, self::CHANGEABLE, true) && $now->lessThan($appointment->starts_at->subHours($hours));
    }

    /**
     * The appointment's services in order (product id, the stylist doing it).
     *
     * @return list<array{productId: string, userId: string}>
     */
    public static function services(SalonAppointment $appointment): array
    {
        return SalonAppointmentService::query()
            ->where('appointment_id', $appointment->id)
            ->where('branch_id', $appointment->branch_id)
            ->orderBy('sort_order')->orderBy('starts_at')
            ->get(['product_id', 'stylist_user_id'])
            ->map(fn (SalonAppointmentService $s) => ['productId' => (string) $s->product_id, 'userId' => (string) $s->stylist_user_id])
            ->all();
    }

    /**
     * @return array<string, mixed>
     */
    public static function props(SalonOnlineShop $shop, SalonAppointment $appointment, CarbonImmutable $now): array
    {
        $menu = new PageMenu((array) $shop->page);
        $stylists = $menu->stylists();
        $services = self::services($appointment);
        $basket = $menu->basketOf(array_column($services, 'productId'));
        $names = [];

        foreach ($services as $line) {
            $names[] = $menu->option($line['productId']) !== null
                ? trim($menu->option($line['productId'])['service']['name'].' '.$menu->option($line['productId'])['option']['label'])
                : ($menu->addOn($line['productId'])['addOn']['name'] ?? 'Service');
        }

        $stylist = $services[0]['userId'] ?? null;
        $consent = $appointment->customer_id === null ? null : MarketingConsent::current($appointment->customer_id);

        return [
            'appointment' => [
                'id' => $appointment->id,
                'status' => $appointment->status?->value,
                'when' => LondonClock::label($appointment->starts_at, 'l j F Y'),
                'time' => LondonClock::label($appointment->starts_at, 'H:i'),
                'stylist' => $stylist === null ? null : ($stylists[$stylist]['firstName'] ?? null),
                'stylistId' => $stylist,
                'services' => $names,
                'firstName' => explode(' ', trim((string) $appointment->client_name))[0],
            ],
            'changeable' => self::changeable($shop, $appointment, $now),
            'canMoveOnline' => $basket !== null,
            'changeWindowHours' => (int) ($shop->page['rules']['changeWindowHours'] ?? 24),
            'consent' => $consent === null ? null : [
                'sms' => $consent['sms']['state'] === 'given',
                'email' => $consent['email']['state'] === 'given',
                'whatsApp' => $consent['whatsApp']['state'] === 'given',
            ],
            'requests' => SalonOnlineBooking::query()
                ->where('branch_id', $shop->branch_id)->where('appointment_id', $appointment->id)
                ->whereIn('kind', ['reschedule', 'cancel', 'preferences'])
                ->orderByDesc('created_at')->limit(5)
                ->get(['id', 'kind', 'status', 'requested_starts_at', 'decision_reason', 'created_at'])
                ->map(fn (SalonOnlineBooking $r) => [
                    'id' => $r->id,
                    'kind' => $r->kind?->value,
                    'status' => $r->status?->value,
                    'when' => $r->kind?->value === 'reschedule' ? LondonClock::label($r->requested_starts_at, 'D j M, H:i') : null,
                    'reason' => (string) $r->decision_reason,
                ])->all(),
        ];
    }
}
