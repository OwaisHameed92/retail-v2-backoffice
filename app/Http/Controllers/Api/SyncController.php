<?php

namespace App\Http\Controllers\Api;

use App\Domain\Shared\Support\TraceId;
use App\Domain\Sync\Actions\PullChanges;
use App\Domain\Sync\Actions\PushChanges;
use App\Domain\Sync\Actions\SayHello;
use App\Domain\Sync\Data\PushInput;
use App\Http\Controllers\Controller;
use App\Http\Middleware\AuthenticateSyncKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Till sync API (modules 2.2 and 2.5, contract v1.3.3 §4): `GET sync/hello`, `POST sync/push` and `GET sync/pull`.
 * Auth, contract version, headers and rate limit are the route group's middleware; the work is in SayHello,
 * PushChanges and PullChanges.
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

    public function pull(Request $request, PullChanges $pull): Response
    {
        $reply = $pull->handle(
            AuthenticateSyncKey::caller($request),
            $request->query('since'),
            $request->query('max'),
            (string) $request->header('X-SSPOS-App-Version'),
            (string) $request->header('X-SSPOS-Register-Id'),
        );

        $response = response()->json($reply);

        // Contract §3: replies may be gzip when the till accepts it; a full page of 5,000 rows shrinks ~30x.
        if (str_contains(strtolower((string) $request->header('Accept-Encoding')), 'gzip') && strlen((string) $response->getContent()) > 1024) {
            $response->setContent((string) gzencode((string) $response->getContent()));
            $response->headers->set('Content-Encoding', 'gzip');
            $response->headers->set('Vary', 'Accept-Encoding');
        }

        return $response;
    }
}
