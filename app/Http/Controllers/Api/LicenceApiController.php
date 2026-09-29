<?php

namespace App\Http\Controllers\Api;

use App\Domain\Licensing\Api\ActivateLicence;
use App\Domain\Licensing\Api\DeactivateDevice;
use App\Domain\Licensing\Api\ValidateLicence;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\ActivateLicenceRequest;
use App\Http\Requests\Api\DeactivateDeviceRequest;
use App\Http\Requests\Api\ValidateLicenceRequest;
use Illuminate\Http\JsonResponse;

/**
 * Per-till licensing (contract v1.4.1 §17.15, §17.7). Thin: validate the body, call one Action, reply JSON.
 * Errors are ApiExceptions rendered as `{code, message, traceId, retryAfterSeconds, rejectedKey, details?}`.
 */
class LicenceApiController extends Controller
{
    public function activate(ActivateLicenceRequest $request, ActivateLicence $activate): JsonResponse
    {
        return self::json($activate->handle($request->licenceKey(), $request->tillRequest()));
    }

    public function validateLicence(ValidateLicenceRequest $request, ValidateLicence $validate): JsonResponse
    {
        return self::json($validate->handle((string) $request->validated('licenceId'), (string) $request->validated('tokenSha256'), $request->tillRequest()));
    }

    public function deactivate(DeactivateDeviceRequest $request, DeactivateDevice $deactivate): JsonResponse
    {
        $note = $request->validated('note');

        return self::json($deactivate->handle((string) $request->validated('registerId'), $request->tillRequest(), (string) $request->validated('reason'), is_string($note) ? $note : null));
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private static function json(array $data): JsonResponse
    {
        return new JsonResponse($data, 200, [], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }
}
