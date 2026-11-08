<?php

namespace App\Http\Controllers\SalonPublic;

use App\Domain\Salon\Actions\WithdrawOnlineBooking;
use App\Domain\Salon\Queries\BookingPageView;
use App\Domain\Salon\Queries\BookingRequestView;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSalonShop;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The "request sent, waiting for the shop" page (W3a, salon pack README §2): read again every few seconds until the
 * till answers; accepted (with the manage link) or declined (with the till's reason). The client may withdraw a
 * request the till has not answered yet. The address carries the request's ULID, of this shop only.
 */
class BookingRequestController extends Controller
{
    public function show(Request $request, string $requestId, BookingRequestView $view): Response
    {
        $shop = ResolveSalonShop::shop($request);
        $props = $view->handle($shop, $requestId);

        abort_if($props === null, 404);

        return Inertia::render('salon-public/request', [
            'shop' => BookingPageView::shop($shop),
            'request' => $props,
            'requestId' => $requestId,
            'embed' => BookingPageController::embed($request),
        ]);
    }

    public function withdraw(Request $request, string $requestId, WithdrawOnlineBooking $withdraw): JsonResponse
    {
        $withdraw->handle(ResolveSalonShop::shop($request), $requestId);

        return response()->json(['result' => 'cancelled']);
    }
}
