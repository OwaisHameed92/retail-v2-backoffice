<?php

namespace App\Http\Controllers\Api;

use App\Domain\Leads\Actions\SubmitTrialRequest;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\StoreTrialRequest;
use Illuminate\Http\JsonResponse;

/**
 * Public trial form API (module 1.10, docs/specs/public-trial-api.md): our marketing website and the hosted /trial
 * page post here. CORS, the per-IP limit and the honeypot run first (PublicFormCors, GuardPublicTrialRequests).
 */
class TrialRequestController extends Controller
{
    public const MESSAGE = 'Thank you. We have your trial request and one of our team will be in touch shortly to set up your free trial.';

    public function store(StoreTrialRequest $request, SubmitTrialRequest $submit): JsonResponse
    {
        $lead = $submit->handle($request->details(), $request->captchaToken());

        return self::accepted($lead->reference());
    }

    /** CORS preflight: PublicFormCors answers it (204 with the allow headers) before this runs. */
    public function preflight(): JsonResponse
    {
        return new JsonResponse(null, 204);
    }

    /** The one success reply, also sent for a honeypot hit so bots learn nothing. */
    public static function accepted(string $reference): JsonResponse
    {
        return new JsonResponse(['reference' => $reference, 'message' => self::MESSAGE], 201);
    }
}
