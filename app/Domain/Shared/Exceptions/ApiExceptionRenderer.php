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

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ApiException => self::response(
                $request, $e->errorCode, $e->getMessage(), $e->httpStatus, $e->retryAfterSeconds, $e->rejectedKey,
            ),
            $e instanceof ValidationException => self::response(
                $request, 'request.invalid', self::validationMessage($e), 400,
            ),
            $e instanceof AuthenticationException => self::response(
                $request, 'unauthenticated', ApiErrorMessages::UNAUTHENTICATED, 401,
            ),
            $e instanceof HttpExceptionInterface => self::fromHttpException($e, $request),
            default => self::response($request, 'server.error', ApiErrorMessages::SERVER_ERROR, 500),
        };
    }

    /**
     * Build the standard error reply. Controllers may call this directly for a non-exception error.
     */
    public static function response(
        Request $request,
        string $code,
        string $message,
        int $status,
        ?int $retryAfterSeconds = null,
        ?string $rejectedKey = null,
    ): JsonResponse {
        $traceId = TraceId::for($request);

        $response = new JsonResponse([
            'code' => $code,
            'message' => $message,
            'traceId' => $traceId,
            'retryAfterSeconds' => $retryAfterSeconds,
            'rejectedKey' => $rejectedKey,
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

        [$code, $message] = match (true) {
            $status === 401 => ['unauthenticated', ApiErrorMessages::UNAUTHENTICATED],
            $status === 403 => ['forbidden', ApiErrorMessages::FORBIDDEN],
            $status === 404 => ['not_found', ApiErrorMessages::NOT_FOUND],
            $status === 405 => ['method_not_allowed', ApiErrorMessages::METHOD_NOT_ALLOWED],
            $status === 413 => ['request.too_large', ApiErrorMessages::TOO_LARGE],
            $status === 429 => ['rate_limited', ApiErrorMessages::RATE_LIMITED],
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
