<?php

namespace App\Http\Support;

use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Branded error pages for the portal and admin (module 7.1): 403, 404, 500 and 503 render the `error` Inertia page
 * when debug is off (with debug on, the framework's page is more useful). Till APIs and JSON callers keep their JSON
 * errors; an expired page (419) goes back with a toast instead of a dead end.
 */
final class InertiaErrorPages
{
    /** @var list<int> */
    public const STATUSES = [403, 404, 500, 503];

    /** Tests keep the framework's responses unless a test turns the pages on. */
    public static bool $inTests = false;

    public static function register(Exceptions $exceptions): void
    {
        $exceptions->respond(fn (Response $response, \Throwable $e, Request $request): Response => self::render($response, $request));
    }

    public static function render(Response $response, Request $request): Response
    {
        $status = $response->getStatusCode();

        if ($request->is('api/*') || ($request->expectsJson() && ! $request->header('X-Inertia'))) {
            return $response;
        }

        if ($status === 419) {
            return back()->with('error', 'The page expired. Try again.');
        }

        if (config('app.debug') || (app()->runningUnitTests() && ! self::$inTests) || ! in_array($status, self::STATUSES, true)) {
            return $response;
        }

        return Inertia::render('error', [
            'status' => $status,
            'home' => $request->is('admin', 'admin/*') ? '/admin' : '/app',
        ])->toResponse($request)->setStatusCode($status);
    }
}
