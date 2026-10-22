<?php

namespace App\Domain\Handheld\Support;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Shared\Support\TraceId;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Every error under `api/pda/*` (called by ApiExceptionRenderer): the legacy PDA keys plus the portal's API envelope,
 *
 *     {"success": false, "code": "PRICE_CHANGE_NOT_ALLOWED", "message": "…", "traceId": "01K…", "retryAfterSeconds": null, "rejectedKey": null}
 *
 * Validation adds `errors` (field → messages, the app's field names) with status 422, as the legacy API; an ApiException's
 * `details` become top-level members (409 TENANT_REQUIRED's `tenants`). Never any
 * exception detail, even with APP_DEBUG on.
 */
final class PdaErrorRenderer
{
    private const SERVER_ERROR = 'Something went wrong on our side. Try again shortly.';

    public static function appliesTo(Request $request): bool
    {
        return $request->is('api/pda', 'api/pda/*');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::response($request, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->retryAfterSeconds, $e->rejectedKey, $e->details ?? []),
            $e instanceof ValidationException => self::response($request, PdaErrors::VALIDATION_FAILED, self::validationMessage($e), 422, extra: ['errors' => $e->errors()]),
            $e instanceof AuthenticationException => self::response($request, PdaErrors::UNAUTHENTICATED, PdaErrors::unauthenticated()->getMessage(), 401),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e, $request),
            default => self::response($request, PdaErrors::SERVER_ERROR, self::SERVER_ERROR, 500),
        };
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function response(Request $request, string $code, string $message, int $status, ?int $retryAfterSeconds = null, ?string $rejectedKey = null, array $extra = []): JsonResponse
    {
        $traceId = TraceId::for($request);
        $response = new JsonResponse([
            'success' => false,
            'code' => $code,
            'message' => $message,
            'traceId' => $traceId,
            'retryAfterSeconds' => $retryAfterSeconds,
            'rejectedKey' => $rejectedKey,
            ...$extra,
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
        $retryAfter = null;

        foreach ($e->getHeaders() as $name => $value) {
            if (strcasecmp((string) $name, 'Retry-After') === 0 && is_numeric($value)) {
                $retryAfter = max(0, (int) $value);
            }
        }

        [$code, $message] = match (true) {
            $status === 401 => [PdaErrors::UNAUTHENTICATED, PdaErrors::unauthenticated()->getMessage()],
            $status === 403 => [PdaErrors::FORBIDDEN, 'This handheld account may not do that.'],
            $status === 404 => [PdaErrors::NOT_FOUND, 'We could not find this record in your shop.'],
            $status === 405 => [PdaErrors::METHOD_NOT_ALLOWED, 'This address does not take that request type.'],
            $status === 429 => [PdaErrors::RATE_LIMITED, 'Too many requests. Try again shortly.'],
            $status === 503 => [PdaErrors::SERVER_BUSY, 'The service is busy. Try again shortly.'],
            $status >= 500 => [PdaErrors::SERVER_ERROR, self::SERVER_ERROR],
            default => [PdaErrors::VALIDATION_FAILED, 'The request is not valid.'],
        };

        return self::response($request, $code, $message, $status >= 500 && $status !== 503 ? 500 : $status, $retryAfter);
    }

    private static function validationMessage(ValidationException $e): string
    {
        $first = collect($e->errors())->flatten()->first();

        return is_string($first) ? 'The request is not valid. '.$first : 'The request is not valid.';
    }
}
