<?php

namespace App\Domain\Workshop\Support;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * Renders every error of `/connectors/*` and `/oauth/token` in BikeBook's shape `{error, message}` (and
 * `error_description` on the OAuth endpoint, RFC 6749 §5.2). Never exception details or traces. Registered in
 * bootstrap/app.php before the till API renderer; other paths are untouched.
 */
final class ConnectorErrorRenderer
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
        return $request->is('connectors', 'connectors/*', 'oauth/token');
    }

    public static function render(Throwable $e, Request $request): JsonResponse
    {
        return match (true) {
            $e instanceof ConnectorException => self::response($request, $e->status, $e->error, $e->getMessage(), $e->headers),
            $e instanceof ValidationException => self::response($request, 400, 'invalid_request', self::firstMessage($e)),
            $e instanceof HttpExceptionInterface => self::http($e, $request),
            default => self::response($request, 500, 'server_error', 'Something went wrong on our side. Try again in a minute.'),
        };
    }

    /**
     * @param  array<string, string>  $headers
     */
    public static function response(Request $request, int $status, string $error, string $message, array $headers = []): JsonResponse
    {
        $body = ['error' => $error, 'message' => $message];

        if ($request->is('oauth/token')) {
            $body['error_description'] = $message;
        }

        return new JsonResponse($body, $status, ['Cache-Control' => 'no-store', ...$headers]);
    }

    private static function http(HttpExceptionInterface $e, Request $request): JsonResponse
    {
        $status = $e->getStatusCode();
        $headers = array_filter(array_map(fn ($v) => is_array($v) ? implode(', ', $v) : (string) $v, $e->getHeaders()), fn ($v) => $v !== '');

        return match (true) {
            $status === 404 => self::response($request, 404, 'not_found', 'Not found.'),
            $status === 405 => self::response($request, 405, 'method_not_allowed', 'This call does not take that method.'),
            $status === 413 => self::response($request, 413, 'invalid_request', 'The request body is larger than 1 MB.'),
            $status === 429 => self::response($request, 429, 'rate_limited', 'Too many requests. Wait and try again.', array_intersect_key($headers, ['Retry-After' => true])),
            $status === 401 => self::response($request, 401, 'unauthorized', 'Send a valid access token or API key.'),
            $status === 403 => self::response($request, 403, 'forbidden', 'This call is not allowed.'),
            $status >= 500 => self::response($request, 500, 'server_error', 'Something went wrong on our side. Try again in a minute.'),
            default => self::response($request, 400, 'invalid_request', 'The request is not valid.'),
        };
    }

    private static function firstMessage(ValidationException $e): string
    {
        foreach ($e->errors() as $messages) {
            foreach ($messages as $message) {
                return $message;
            }
        }

        return 'The request is not valid.';
    }
}
