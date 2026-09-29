<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Support\TraceId;
use App\Domain\Sync\Actions\PushChanges;
use App\Domain\Sync\Actions\SayHello;
use App\Domain\Sync\Data\PushInput;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSyncKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Till sync API (module 2.2, contract v1.3.3 §4): `GET sync/hello` and `POST sync/push`. Auth, contract version,
 * headers and rate limit are the route group's middleware; the work is in SayHello and PushChanges.
 */
class SyncController extends Controller
{
    public function hello(Request $request, SayHello $hello): JsonResponse
    {
        return response()->json($hello->handle(
            AuthenticateSyncKey::caller($request),
            (string) $request->header('X-SSPOS-App-Version'),
            (string) $request->header('X-SSPOS-Register-Id'),
        ));
    }

    public function push(Request $request, PushChanges $push): JsonResponse
    {
        $reply = $push->handle(AuthenticateSyncKey::caller($request), new PushInput(
            (string) $request->getContent(),
            $request->header('Content-Encoding'),
            $request->header('X-SSPOS-Sync-Mode'),
            $request->header('X-SSPOS-Upload-Id'),
            $request->header('Idempotency-Key'),
            (string) $request->header('X-SSPOS-App-Version'),
            (string) $request->header('X-SSPOS-Register-Id'),
            TraceId::for($request),
        ));

        $response = response()->json($reply->body, $reply->status);

        if ($reply->replayed) {
            $response->headers->set('Idempotency-Replayed', 'true');
        }

        return $response;
    }
}
