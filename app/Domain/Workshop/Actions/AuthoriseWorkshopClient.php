<?php

namespace App\Domain\Workshop\Actions;

use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Workshop\Enums\ConnectionStatus;
use App\Domain\Workshop\Enums\WorkshopTokenKind;
use App\Domain\Workshop\Models\WorkshopToken;
use App\Domain\Workshop\Support\ConnectorSecret;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The shop owner's consent on `/oauth/authorize` (WORKSHOP-BIKEBOOK.md §1.2): connects the chosen shop (needs the
 * `workshop_integration` add-on, ConnectWorkshop) and makes a one-time authorisation code for BikeBook, valid
 * 10 minutes, bound to the redirect URI and scopes. Returns the plain code (it goes only into the redirect).
 */
final class AuthoriseWorkshopClient
{
    public function __construct(private readonly ConnectWorkshop $connect, private readonly RecordAudit $audit) {}

    /**
     * @param  list<string>  $scopes
     */
    public function handle(Branch $branch, User $user, string $redirectUri, array $scopes): string
    {
        ConnectWorkshop::ensureAllowed($branch);

        return DB::transaction(function () use ($branch, $user, $redirectUri, $scopes): string {
            $connection = $this->connect->handle($branch, ConnectionStatus::Connected, $user);
            $code = ConnectorSecret::newToken(WorkshopTokenKind::Code->prefix());

            WorkshopToken::withoutCompanyScope()->create([
                'company_id' => $connection->company_id,
                'connection_id' => $connection->id,
                'kind' => WorkshopTokenKind::Code,
                'token_hash' => ConnectorSecret::hashToken($code),
                'scopes' => implode(' ', $scopes),
                'redirect_uri' => $redirectUri,
                'user_id' => $user->id,
                'expires_at' => CarbonImmutable::now('UTC')->addSeconds((int) config('workshop.bikebook.code_seconds', 600)),
            ]);

            $this->audit->handle('workshop.oauth_consented', $connection, null, ['scopes' => $scopes], ['branch' => $branch->name], $user);

            return $code;
        });
    }
}
