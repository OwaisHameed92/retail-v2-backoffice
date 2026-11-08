<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Licensing\Enums\AddOn;
use App\Domain\Licensing\Support\BranchAddOns;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Workshop\Enums\WorkshopTokenKind;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopToken;
use App\Domain\Workshop\Support\ConnectorException;
use App\Domain\Workshop\Support\ConnectorSecret;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Who is calling the connector (WORKSHOP-BIKEBOOK.md §1.2): an OAuth access token (`Authorization: Bearer`) or the
 * shop's API key (`ApiKey` header). Never a key or token in the query string (400, checked first). Refusals:
 *
 * - 401: no credentials, unknown / expired / revoked token or key, or the connection is not connected (disconnected,
 *   pending): BikeBook marks the connection failed. Failed attempts per IP are limited (30 in 15 min, then 401s).
 * - 403: a `tenant-id` that is not the shop the token or key belongs to; the shop's licence no longer includes
 *   `workshop_integration`; the business is suspended or cancelled, or the shop is closed.
 *
 * @return array{0: WorkshopConnection, 1: Company, 2: string} the connection, its business and 'oauth' or 'key'
 */
final class AuthenticateConnector
{
    private const QUERY_SECRETS = ['apikey', 'api_key', 'access_token', 'token', 'key'];

    /**
     * @return array{0: WorkshopConnection, 1: Company, 2: string}
     */
    public function handle(Request $request): array
    {
        foreach (array_keys($request->query()) as $name) {
            if (in_array(strtolower((string) $name), self::QUERY_SECRETS, true)) {
                throw ConnectorException::invalid('Send the API key in the ApiKey header or a Bearer token. Keys are never accepted in the URL.');
            }
        }

        $limiter = 'workshop-auth:'.$request->ip();

        if (RateLimiter::tooManyAttempts($limiter, (int) config('workshop.failed_auth_per_ip', 30))) {
            throw ConnectorException::unauthorized('Too many failed sign-ins from this address. Try again later.');
        }

        [$connection, $kind] = $this->lookup($request);

        if ($connection === null || ! $connection->status->carriesTraffic()) {
            RateLimiter::hit($limiter, 900);

            throw ConnectorException::unauthorized($connection === null ? 'The access token or API key is not valid.' : 'This shop is not connected to BikeBook.');
        }

        $tenant = trim((string) $request->header('tenant-id', ''));

        if ($tenant !== '' && $tenant !== $connection->branch_id) {
            throw ConnectorException::forbidden('tenant-id is not the shop this token or key was issued for.');
        }

        $company = Company::query()->find($connection->company_id);
        $branch = Branch::withoutCompanyScope()->whereKey($connection->branch_id)->first();

        if ($company === null || in_array($company->status, [CompanyStatus::Suspended, CompanyStatus::Cancelled], true) || $branch === null || ! $branch->is_active) {
            throw ConnectorException::forbidden('This shop\'s account is not active. The shop should contact Switch & Save.');
        }

        if (! BranchAddOns::has($branch, AddOn::WorkshopIntegration)) {
            throw ConnectorException::forbidden('Workshop integration is not in this shop\'s licence. The shop should contact Switch & Save.');
        }

        return [$connection, $company, $kind];
    }

    /**
     * @return array{0: WorkshopConnection|null, 1: string}
     */
    private function lookup(Request $request): array
    {
        $bearer = (string) $request->bearerToken();
        $key = trim((string) $request->header('ApiKey', ''));

        if ($bearer !== '') {
            if (! ConnectorSecret::looksLikeToken($bearer, WorkshopTokenKind::Access->prefix())) {
                return [null, 'oauth'];
            }

            $token = WorkshopToken::withoutCompanyScope()->where('token_hash', ConnectorSecret::hashToken($bearer))
                ->where('kind', WorkshopTokenKind::Access->value)->first();

            return [$token !== null && $token->isUsable(CarbonImmutable::now('UTC'))
                ? WorkshopConnection::withoutCompanyScope()->whereKey($token->connection_id)->first()
                : null, 'oauth'];
        }

        if ($key === '' || ! ConnectorSecret::looksLikeKey($key)) {
            return [null, 'key'];
        }

        $connection = WorkshopConnection::withoutCompanyScope()->whereIn('api_key_hash', ConnectorSecret::keyCandidates($key))->first();

        return [$connection !== null && collect(ConnectorSecret::keyCandidates($key))->contains(fn (string $h) => hash_equals((string) $connection->api_key_hash, $h)) ? $connection : null, 'key'];
    }
}
