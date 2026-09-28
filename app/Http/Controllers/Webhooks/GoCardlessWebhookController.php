<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\Billing\GoCardless\Actions\ReceiveGoCardlessWebhook;
use App\Domain\Shared\Support\TraceId;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /webhooks/gocardless` (module 1.12). Public; authenticated by the `Webhook-Signature` HMAC. A bad
 * signature gets 498 (GoCardless' convention for "token invalid"); otherwise 200 with counts, quickly: events are
 * processed in a queued job.
 */
class GoCardlessWebhookController extends Controller
{
    public function __invoke(Request $request, ReceiveGoCardlessWebhook $receive): JsonResponse
    {
        $result = $receive->handle($request->getContent(), $request->header('Webhook-Signature'));

        if ($result === null) {
            return response()->json([
                'code' => 'signature.invalid',
                'message' => 'The webhook signature is not valid.',
                'traceId' => TraceId::for($request),
                'retryAfterSeconds' => null,
                'rejectedKey' => null,
            ], 498);
        }

        return response()->json($result);
    }
}
