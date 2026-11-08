<?php

namespace App\Http\Middleware;

use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Workshop\Actions\AuthenticateConnector;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Support\CallLogger;
use App\Domain\Workshop\Support\ConnectorException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * The BikeBook connector's door (WORKSHOP-BIKEBOOK.md §1.2, §2, §7): body ≤ 1 MB (413), credentials and tenant
 * (AuthenticateConnector), 600 requests a minute per connection (429 + Retry-After), then the call runs as the
 * shop's business (CurrentCompany, restored after) and is logged (CallLogger), whatever its answer. The connection
 * is available to controllers as the request attribute `workshop.connection`.
 */
class AuthenticateWorkshopConnector
{
    public const ATTRIBUTE = 'workshop.connection';

    public function __construct(
        private readonly AuthenticateConnector $auth,
        private readonly CurrentCompany $tenancy,
        private readonly CallLogger $log,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $started = hrtime(true);

        if ((int) $request->header('Content-Length', '0') > (int) config('workshop.max_body_bytes', 1048576)) {
            throw new ConnectorException(413, 'invalid_request', 'The request body is larger than 1 MB.');
        }

        [$connection, $company, $kind] = $this->auth->handle($request);
        $request->attributes->set(self::ATTRIBUTE, $connection);

        try {
            $limiter = 'workshop-connector:'.$connection->id;

            if (! RateLimiter::attempt($limiter, (int) config('workshop.requests_per_minute', 600), fn () => true, 60)) {
                throw ConnectorException::rateLimited(RateLimiter::availableIn($limiter));
            }

            $response = $this->tenancy->runAs($company, fn () => $next($request));
        } catch (ConnectorException $e) {
            $this->record($connection, $request, $e->status, $started, $kind, $e->error);

            throw $e;
        }

        $this->record($connection, $request, $response->getStatusCode(), $started, $kind, self::errorOf($response));

        return $response;
    }

    private function record(WorkshopConnection $connection, Request $request, int $status, int $started, string $kind, ?string $error): void
    {
        $this->log->log($connection, $request, $status, (int) round((hrtime(true) - $started) / 1_000_000), $kind, $error);
    }

    private static function errorOf(Response $response): ?string
    {
        if ($response->getStatusCode() < 400) {
            return null;
        }

        $body = json_decode((string) $response->getContent(), true);

        return is_array($body) && is_string($body['error'] ?? null) ? $body['error'] : null;
    }
}
