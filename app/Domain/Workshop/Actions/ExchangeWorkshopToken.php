<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Workshop\Enums\WorkshopTokenKind;
use App\Domain\Workshop\Models\WorkshopConnection;
use App\Domain\Workshop\Models\WorkshopToken;
use App\Domain\Workshop\Support\ConnectorException;
use App\Domain\Workshop\Support\ConnectorSecret;
use App\Domain\Workshop\Support\OAuthClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use SensitiveParameter;

/**
 * `POST /oauth/token` (WORKSHOP-BIKEBOOK.md §1.2, RFC 6749 §4.1.3, §6): grants `authorization_code` and
 * `refresh_token` to our one client, BikeBook. Answers `{access_token, refresh_token, expires_in, token_type: "Bearer",
 * tenant, scope}`; `tenant` is the shop's branch id, which BikeBook sends back as `tenant-id`.
 *
 * - A code is used once, within 10 minutes, with the redirect URI it was made for. A code used twice revokes every
 *   token it gave (RFC 6749 §4.1.2).
 * - Refresh tokens last a year and rotate on use: the old one is revoked, a new pair is given.
 * - The connection must still be connected (a disconnected shop's tokens are revoked anyway).
 */
final class ExchangeWorkshopToken
{
    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    public function handle(array $input, ?string $clientId, #[SensitiveParameter] ?string $clientSecret): array
    {
        if (! OAuthClient::checkSecret($clientId, $clientSecret)) {
            throw ConnectorException::oauth(401, 'invalid_client', 'Unknown client or wrong client secret.');
        }

        return match ($input['grant_type'] ?? null) {
            'authorization_code' => $this->fromCode((string) ($input['code'] ?? ''), (string) ($input['redirect_uri'] ?? '')),
            'refresh_token' => $this->fromRefresh((string) ($input['refresh_token'] ?? '')),
            null, '' => throw ConnectorException::oauth(400, 'invalid_request', 'grant_type is required.'),
            default => throw ConnectorException::oauth(400, 'unsupported_grant_type', 'Use authorization_code or refresh_token.'),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function fromCode(#[SensitiveParameter] string $code, string $redirectUri): array
    {
        $seen = $this->find($code, WorkshopTokenKind::Code, false);

        if ($seen !== null && $seen->used_at !== null) {
            // A code used twice: whoever holds it may be an attacker; revoke what it gave (outside the failing call).
            WorkshopToken::withoutCompanyScope()->where('parent_id', $seen->id)->whereNull('revoked_at')->update(['revoked_at' => CarbonImmutable::now('UTC')]);
        }

        return DB::transaction(function () use ($code, $redirectUri): array {
            $row = $this->find($code, WorkshopTokenKind::Code);
            $now = CarbonImmutable::now('UTC');

            if ($row === null || $row->used_at !== null || ! $row->isUsable($now)) {
                throw ConnectorException::oauth(400, 'invalid_grant', 'The authorisation code is unknown, used or expired.');
            }

            if ($row->redirect_uri !== $redirectUri) {
                throw ConnectorException::oauth(400, 'invalid_grant', 'redirect_uri does not match the one the code was made for.');
            }

            $row->forceFill(['used_at' => $now])->save();

            return $this->issue($this->connection($row), $row->scopes, $row->id);
        });
    }

    /**
     * @return array<string, mixed>
     */
    private function fromRefresh(#[SensitiveParameter] string $token): array
    {
        return DB::transaction(function () use ($token): array {
            $row = $this->find($token, WorkshopTokenKind::Refresh);
            $now = CarbonImmutable::now('UTC');

            if ($row === null || ! $row->isUsable($now)) {
                throw ConnectorException::oauth(400, 'invalid_grant', 'The refresh token is unknown, revoked or expired.');
            }

            $connection = $this->connection($row);
            $row->forceFill(['revoked_at' => $now, 'used_at' => $now])->save();
            WorkshopToken::withoutCompanyScope()->where('parent_id', $row->parent_id)->where('kind', WorkshopTokenKind::Access->value)
                ->whereNull('revoked_at')->update(['revoked_at' => $now]);

            return $this->issue($connection, $row->scopes, $row->parent_id ?? $row->id);
        });
    }

    private function find(#[SensitiveParameter] string $value, WorkshopTokenKind $kind, bool $lock = true): ?WorkshopToken
    {
        if (! ConnectorSecret::looksLikeToken($value, $kind->prefix())) {
            return null;
        }

        return WorkshopToken::withoutCompanyScope()->where('token_hash', ConnectorSecret::hashToken($value))->where('kind', $kind->value)
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
    }

    private function connection(WorkshopToken $row): WorkshopConnection
    {
        $connection = WorkshopConnection::withoutCompanyScope()->whereKey($row->connection_id)->first();

        if ($connection === null || ! $connection->status->carriesTraffic()) {
            throw ConnectorException::oauth(400, 'invalid_grant', 'This shop is no longer connected to BikeBook.');
        }

        return $connection;
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(WorkshopConnection $connection, string $scopes, string $parentId): array
    {
        $now = CarbonImmutable::now('UTC');
        $seconds = (int) config('workshop.bikebook.access_token_seconds', 86400);
        $access = ConnectorSecret::newToken(WorkshopTokenKind::Access->prefix());
        $refresh = ConnectorSecret::newToken(WorkshopTokenKind::Refresh->prefix());

        foreach ([[WorkshopTokenKind::Access, $access, $now->addSeconds($seconds)], [WorkshopTokenKind::Refresh, $refresh, $now->addDays((int) config('workshop.bikebook.refresh_token_days', 365))]] as [$kind, $value, $expires]) {
            WorkshopToken::withoutCompanyScope()->create([
                'company_id' => $connection->company_id, 'connection_id' => $connection->id, 'kind' => $kind,
                'token_hash' => ConnectorSecret::hashToken($value), 'scopes' => $scopes, 'parent_id' => $parentId, 'expires_at' => $expires,
            ]);
        }

        return [
            'access_token' => $access,
            'refresh_token' => $refresh,
            'expires_in' => $seconds,
            'token_type' => 'Bearer',
            'tenant' => $connection->branch_id,
            'scope' => $scopes,
        ];
    }
}
