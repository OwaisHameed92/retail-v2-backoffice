<?php

namespace App\Http\Controllers\Api;

use App\Domain\Salon\Actions\AnswerQueueJoin;
use App\Domain\Salon\Actions\RecordSalonHeartbeat;
use App\Domain\Salon\Actions\StoreBookingPage;
use App\Domain\Salon\Actions\StoreQueueState;
use App\Domain\Salon\Online\SalonJsonBody;
use App\Domain\Salon\Queries\QueueJoinsForTill;
use App\Domain\Salon\Support\SalonOnlineSwitch;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSyncKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The five salon calls of salon pack 2026-11-08 (README §4, W3a): `PUT sync/salon/booking-page`, `PUT …/heartbeat`,
 * `GET …/queue-joins`, `POST …/queue-joins/{joinId}/answer`, `PUT …/queue`. Auth, contract header, the switch, the
 * salon check and the limits are the route group's middleware (GuardSalonOnline); the work is in the actions. Bodies
 * are read raw (SalonJsonBody: gzip or plain, size-limited), never logged.
 */
class SalonOnlineController extends Controller
{
    public function bookingPage(Request $request, StoreBookingPage $store): JsonResponse
    {
        return response()->json($store->handle(AuthenticateSyncKey::caller($request), $this->body($request, page: true)));
    }

    public function heartbeat(Request $request, RecordSalonHeartbeat $record): JsonResponse
    {
        return response()->json($record->handle(AuthenticateSyncKey::caller($request), $this->body($request)));
    }

    public function queueJoins(Request $request, QueueJoinsForTill $joins): JsonResponse
    {
        return response()->json($joins->handle(AuthenticateSyncKey::caller($request), $request->query('max')));
    }

    public function answer(Request $request, string $joinId, AnswerQueueJoin $answer): JsonResponse
    {
        return response()->json($answer->handle(AuthenticateSyncKey::caller($request), $joinId, $this->body($request)));
    }

    public function queue(Request $request, StoreQueueState $store): JsonResponse
    {
        return response()->json($store->handle(AuthenticateSyncKey::caller($request), $this->body($request)));
    }

    private function body(Request $request, bool $page = false): mixed
    {
        $max = $page ? SalonOnlineSwitch::maxPageBytes() : SalonJsonBody::SMALL_MAX_BYTES;

        return SalonJsonBody::read((string) $request->getContent(), $request->header('Content-Encoding'), $max, $page)['data'];
    }
}
