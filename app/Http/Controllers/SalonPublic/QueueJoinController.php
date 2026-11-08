<?php

namespace App\Http\Controllers\SalonPublic;

use App\Domain\Salon\Actions\JoinSalonQueue;
use App\Domain\Salon\Online\SalonOnlineShops;
use App\Domain\Salon\Online\SalonShopStatus;
use App\Domain\Salon\Queries\BookingPageView;
use App\Domain\Salon\Queries\QueuePosition;
use App\Http\Controllers\Controller;
use App\Http\Middleware\ResolveSalonShop;
use App\Http\Requests\SalonPublic\BookOnlineRequest;
use App\Http\Requests\SalonPublic\JoinQueueRequest;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Queue join from a phone `{queueJoinUrl}` (W3a, salon pack README §4.3–§4.5, §6): the door QR's page, the join, and
 * the "my position" page (read again every 20 seconds). Offline or paused: "please ask at the desk". Never another
 * client's name or phone.
 */
class QueueJoinController extends Controller
{
    public function show(Request $request): Response
    {
        $shop = ResolveSalonShop::shop($request);
        $page = (array) $shop->page;
        $options = [];

        foreach ($page['services'] as $service) {
            foreach ($service['options'] as $option) {
                $options[] = ['id' => $option['productId'], 'name' => trim($service['name'].($option['label'] !== '' ? ' – '.$option['label'] : '')), 'durationMin' => $option['durationMin']];
            }
        }

        return Inertia::render('salon-public/queue', [
            'shop' => BookingPageView::shop($shop),
            'open' => SalonShopStatus::queueOpen($shop, CarbonImmutable::now('UTC')),
            'services' => $options,
            'stylists' => array_map(fn (array $s) => ['userId' => $s['userId'], 'firstName' => $s['firstName']], $page['stylists']),
            'waiting' => isset($shop->queue['waiting']) ? (int) $shop->queue['waiting'] : null,
            'walkInMinutesToWait' => isset($shop->queue['walkInMinutesToWait']) ? (int) $shop->queue['walkInMinutesToWait'] : null,
            'src' => BookOnlineRequest::src($request->query('src')),
        ]);
    }

    public function store(JoinQueueRequest $request, JoinSalonQueue $join): JsonResponse
    {
        $shop = ResolveSalonShop::shop($request);
        $row = $join->handle($shop, $request->fields());

        return response()->json(['redirect' => SalonOnlineShops::addresses($shop)['queueJoinUrl'].'/'.$row->id], 201);
    }

    public function position(Request $request, string $joinId, QueuePosition $position): Response
    {
        $shop = ResolveSalonShop::shop($request);
        $props = $position->handle($shop, $joinId);

        abort_if($props === null, 404);

        return Inertia::render('salon-public/queue-position', ['shop' => BookingPageView::shop($shop), 'position' => $props]);
    }
}
