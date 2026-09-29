<?php

namespace App\Domain\Shared\Exceptions;

use App\Domain\Shared\Support\TraceId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every error under `api/*` as `{code, message, traceId, retryAfterSeconds, rejectedKey}`.
 * Never includes exception details or stack traces, even with APP_DEBUG on.
 *
 * Framework errors get contract codes (`area.snake_case`, contract v1.4.1 §17.11 rule 10,
 * licensing/samples/error-codes.json): request.invalid, auth.invalid_key, rate.limited, server.busy, server.error;
 * on the till's endpoints every other 4xx is 400 request.invalid (module 2.6: only contract codes reach a till);
 * elsewhere under api/* (the public trial form) statuses the contract has no code for use request.* / auth.* names.
 *
 * Registered in bootstrap/app.php: `ApiExceptionRenderer::register($exceptions)`.
 */
final class ApiExceptionRenderer
{
    public static function register(Exceptions $exceptions): void
    {
        $exceptions->render(function (Throwable $e, Request $request): ?JsonResponse {
            if (! self::appliesTo($request) || $e instanceof HttpResponseException) {
                return null;
            }

            return self::render($e, $request);
        });
    }

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/*');
    }

    /** The §17 licensing endpoints (licence, devices, cloud migration). */
    public static function isLicenceApi(Request $request): bool
    {
        return $request->is('api/v1/licence/*', 'api/v1/devices/*', 'api/v1/cloud/*');
    }

    /** The EPOS till's endpoints (contract v1.4.1): sync, licence, devices and cloud migration. */
    public static function isTillApi(Request $request): bool
    {
        return $request->is('api/v1/sync', 'api/v1/sync/*', 'api/v1/licence/*', 'api/v1/devices/*', 'api/v1/cloud/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::response(
                $request, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->retryAfterSeconds, $e->rejectedKey, $e->details,
            ),
            $e instanceof ValidationException => self::response(
                $request, 'request.invalid', self::validationMessage($e), 400,
            ),
            $e instanceof AuthenticationException => self::response(
                $request, 'auth.invalid_key', ApiErrorMessages::UNAUTHENTICATED, 401,
            ),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e, $request),
            default => self::response($request, 'server.error', ApiErrorMessages::SERVER_ERROR, 500),
        };
    }

    /**
     * Build the standard error reply. Controllers may call this directly for a non-exception error.
     * `details` is added when given, and always (null when none) on the licensing endpoints (contract §17.12).
     *
     * @param  array<string, mixed>|null  $details
     */
    public static function response(
        Request $request,
        string $code,
        string $message,
        int $status,
        ?int $retryAfterSeconds = null,
        ?string $rejectedKey = null,
        ?array $details = null,
    ): JsonResponse {
        $traceId = TraceId::for($request);

        $response = new JsonResponse([
            'code' => $code,
            'message' => $message,
            'traceId' => $traceId,
            'retryAfterSeconds' => $retryAfterSeconds,
            'rejectedKey' => $rejectedKey,
            // §17.12: licensing errors always carry `details` (null when none, as every licensing/samples/error.*).
            ...($details === null && ! self::isLicenceApi($request) ? [] : ['details' => $details]),
        ], $status);

        $response->headers->set(TraceId::HEADER, $traceId);

        if ($retryAfterSeconds !== null) {
            $response->headers->set('Retry-After', (string) $retryAfterSeconds);
        }

        return $response;
    }

    private static function fromHttpException(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();
        $retryAfter = self::retryAfter($e->getHeaders());

        // The till's endpoints use only codes of the contract (error-codes.json): a framework 403/404/405 there (a call
        // the portal does not offer, a wrong method) is 400 request.invalid, which the till shows and retries later.
        if (self::isTillApi($request) && ! in_array($status, [401, 413, 429], true) && $status < 500) {
            return self::response($request, 'request.invalid', ApiErrorMessages::INVALID.' The portal does not offer this call.', 400);
        }

        [$code, $message] = match (true) {
            $status === 401 => ['auth.invalid_key', ApiErrorMessages::UNAUTHENTICATED],
            $status === 403 => ['auth.forbidden', ApiErrorMessages::FORBIDDEN],
            $status === 404 => ['request.not_found', ApiErrorMessages::NOT_FOUND],
            $status === 405 => ['request.method_not_allowed', ApiErrorMessages::METHOD_NOT_ALLOWED],
            $status === 413 => ['batch.too_large', ApiErrorMessages::TOO_LARGE],
            $status === 429 => ['rate.limited', ApiErrorMessages::RATE_LIMITED],
            $status === 503 => ['server.busy', ApiErrorMessages::BUSY],
            $status >= 500 => ['server.error', ApiErrorMessages::SERVER_ERROR],
            default => ['request.invalid', ApiErrorMessages::INVALID],
        };

        if ($status >= 500 && $status !== 503) {
            $status = 500;
        }

        return self::response($request, $code, $message, $status, $retryAfter);
    }

    /**
     * @param  array<string, mixed>  $headers
     */
    private static function retryAfter(array $headers): ?int
    {
        foreach ($headers as $name => $value) {
            if (strcasecmp($name, 'Retry-After') === 0 && is_numeric($value)) {
                return max(0, (int) $value);
            }
        }

        return null;
    }

    private static function validationMessage(ValidationException $e): string
    {
        $first = collect($e->errors())->flatten()->first();

        return is_string($first) ? ApiErrorMessages::INVALID.' '.$first : ApiErrorMessages::INVALID;
    }
}
