<?php

namespace App\Http\Controllers\Webhooks;

use App\Domain\OnlineStore\Actions\RecordCardPayment;
use App\Domain\OnlineStore\Support\OnlineStoreSwitch;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * `POST /webhooks/dna/{store}` (online store OS-9): DNA Payments' server-to-server result for a card order, sent to
 * the address StartCardPayment gave DNA. Public (no session, no CSRF); trusted only through the result's signature
 * (RecordCardPayment). 200 once handled, so DNA stops retrying; 403 bad signature; 404 unknown store or order (and
 * while the online store is switched off); 422 wrong amount.
 */
class DnaPaymentWebhookController extends Controller
{
    public function __invoke(Request $request, RecordCardPayment $record, string $store): JsonResponse
    {
        if (! OnlineStoreSwitch::enabled()) {
            return response()->json(['message' => 'Not found.'], 404);
        }

        /** @var array<string, mixed> $payload */
        $payload = $request->json()->count() > 0 ? $request->json()->all() : $request->all();
        $outcome = $record->handle($store, $payload);

        return response()->json(['received' => $outcome->httpStatus() === 200, 'outcome' => $outcome->value], $outcome->httpStatus());
    }
}
