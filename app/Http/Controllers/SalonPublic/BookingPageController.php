<?php

namespace App\Http\Controllers\SalonPublic;

use App\Domain\Salon\Actions\RequestOnlineBooking;
use App\Domain\Salon\Availability\PageMenu;
use App\Domain\Salon\Online\BookingTimes;
use App\Domain\Salon\Online\SalonOnlineShops;
use App\Domain\Salon\Queries\BookingPageView;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSalonShop;
use App\Http\Middleware\SecurityHeaders;
use App\Http\Requests\SalonPublic\BookOnlineRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The public booking page `{bookingUrl}` (W3a, salon pack README §4.1, §9, §10): the page, its free times for the
 * client's basket, and the request. `?src=` is recorded with the request; unknown parameters are ignored. `embed=1`
 * hides our header and footer and lets any site frame this page and the "request sent" page (SecurityHeaders).
 */
class BookingPageController extends Controller
{
    public function show(Request $request): Response
    {
        $shop = ResolveSalonShop::shop($request);
        $embed = self::embed($request);

        return Inertia::render('salon-public/book', [
            ...BookingPageView::props($shop, CarbonImmutable::now('UTC')),
            'embed' => $embed,
            'src' => BookOnlineRequest::src($request->query('src')),
        ]);
    }

    public function times(Request $request): JsonResponse
    {
        $shop = ResolveSalonShop::shop($request);
        $strings = fn (string $key): array => array_values(array_filter(array_map(fn ($v) => is_string($v) ? $v : '', array_slice((array) $request->query($key, []), 0, 10))));
        $basket = (new PageMenu((array) $shop->page))->basket($strings('options'), $strings('addOns'));
        $days = $basket === null ? null : BookingTimes::days($shop, $basket, (string) $request->query('stylist', 'any'), CarbonImmutable::now('UTC'));

        return $days === null
            ? response()->json(['message' => 'Choose services and a stylist from the list.', 'days' => []], 422)
            : response()->json(['days' => $days]);
    }

    public function store(BookOnlineRequest $request, RequestOnlineBooking $book): JsonResponse
    {
        $shop = ResolveSalonShop::shop($request);
        $booking = $book->handle($shop, $request->fields());
        $url = SalonOnlineShops::addresses($shop)['bookingUrl'].'/r/'.$booking->id;

        return response()->json(['redirect' => $url.(self::embed($request) ? '?embed=1' : '')], 201);
    }

    /** `embed=1`: no header or footer, and this page may be framed by any site. */
    public static function embed(Request $request): bool
    {
        $embed = $request->query('embed') === '1' || $request->input('embed') === true || $request->input('embed') === '1';

        if ($embed && $request->isMethod('GET')) {
            $request->attributes->set(SecurityHeaders::FRAME_ANY_SITE, true);
        }

        return $embed;
    }
}
