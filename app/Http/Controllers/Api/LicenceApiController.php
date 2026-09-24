<?php

namespace App\Http\Controllers\Api;

use App\Domain\Licensing\Api\ActivateLicence;
use App\Domain\Licensing\Api\CheckInLicence;
use App\Domain\Licensing\Api\DeactivateLicence;
use App\Domain\Licensing\Signing\Jwks;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActivateLicenceRequest;
use App\Http\Requests\Api\CheckInLicenceRequest;
use App\Http\Requests\Api\DeactivateLicenceRequest;
use Illuminate\Http\JsonResponse;

/**
 * Licence API v1 (module 1.5, docs/specs/licence-api-v1.md). Thin: validate the body, call one Action, reply
 * JSON. Errors are ApiExceptions rendered as `{code, message, traceId, retryAfterSeconds, rejectedKey}`.
 */
class LicenceApiController extends Controller
{
    public function activate(ActivateLicenceRequest $request, ActivateLicence $activate): JsonResponse
    {
        return self::json($activate->handle($request->tillRequest()));
    }

    public function checkIn(CheckInLicenceRequest $request, CheckInLicence $checkIn): JsonResponse
    {
        return self::json($checkIn->handle($request->tillRequest()));
    }

    public function deactivate(DeactivateLicenceRequest $request, DeactivateLicence $deactivate): JsonResponse
    {
        return self::json($deactivate->handle($request->tillRequest()));
    }

    public function keys(Jwks $jwks): JsonResponse
    {
        return self::json($jwks->current());
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
