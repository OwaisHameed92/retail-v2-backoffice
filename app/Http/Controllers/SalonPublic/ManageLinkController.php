<?php

namespace App\Http\Controllers\SalonPublic;

use App\Domain\Salon\Actions\RequestAppointmentChange;
use App\Domain\Salon\Online\BookingTimes;
use App\Domain\Salon\Queries\BookingPageView;
use App\Domain\Salon\Queries\ManagedAppointment;
use App\Domain\TillData\Enums\SalonOnlineBookingKind;
use App\Domain\TillData\Models\SalonAppointment;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSalonShop;
use App\Http\Requests\SalonPublic\ChangeAppointmentRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The manage link `{manageUrl}{appointmentId}.{token}` (W3a, salon pack README §8): no password; the token is checked
 * in constant time against the shop's newest key (ManageLinkToken) before anything is shown or sent. A wrong or old
 * link shows "this link does not open a booking" (404). Moves and cancels are new requests, until
 * `changeWindowHours` before the start; marketing choices any time. The token is never logged.
 */
class ManageLinkController extends Controller
{
    public function show(Request $request, string $link): Response
    {
        $shop = ResolveSalonShop::shop($request);
        $opened = ManagedAppointment::open($shop, $link, CarbonImmutable::now('UTC'));

        $response = Inertia::render('salon-public/manage', [
            'shop' => BookingPageView::shop($shop),
            'link' => $opened === null ? null : $link,
            'stylists' => $opened === null ? [] : array_map(fn (array $s) => ['userId' => $s['userId'], 'firstName' => $s['firstName'], 'group' => $s['group']], $shop->page['stylists'] ?? []),
            ...($opened['props'] ?? ['appointment' => null]),
        ])->toResponse($request);

        return $opened === null ? $response->setStatusCode(404) : $response;
    }

    public function times(Request $request, string $link): JsonResponse
    {
        $shop = ResolveSalonShop::shop($request);
        $now = CarbonImmutable::now('UTC');
        $appointment = $this->appointment($request, $link);
        $basket = ManagedAppointment::basket($shop, $appointment);
        $days = $basket === null ? null : BookingTimes::days($shop, $basket, (string) $request->query('stylist', 'any'), $now);

        return $days === null
            ? response()->json(['message' => 'This appointment cannot be moved online. Please ring the shop.', 'days' => []], 422)
            : response()->json(['days' => $days]);
    }

    public function move(ChangeAppointmentRequest $request, string $link, RequestAppointmentChange $change): JsonResponse
    {
        return $this->send($request, $link, SalonOnlineBookingKind::Reschedule, $change);
    }

    public function cancel(ChangeAppointmentRequest $request, string $link, RequestAppointmentChange $change): JsonResponse
    {
        return $this->send($request, $link, SalonOnlineBookingKind::Cancel, $change);
    }

    public function preferences(ChangeAppointmentRequest $request, string $link, RequestAppointmentChange $change): JsonResponse
    {
        return $this->send($request, $link, SalonOnlineBookingKind::Preferences, $change);
    }

    private function send(ChangeAppointmentRequest $request, string $link, SalonOnlineBookingKind $kind, RequestAppointmentChange $change): JsonResponse
    {
        $booking = $change->handle(ResolveSalonShop::shop($request), $this->appointment($request, $link), $kind, $request->fields());

        return response()->json(['result' => 'sent', 'reference' => $booking->reference], 201);
    }

    private function appointment(Request $request, string $link): SalonAppointment
    {
        $opened = ManagedAppointment::open(ResolveSalonShop::shop($request), $link, CarbonImmutable::now('UTC'));

        abort_if($opened === null, 404);

        return $opened['model'];
    }
}
