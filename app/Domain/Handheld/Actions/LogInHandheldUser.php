<?php

namespace App\Domain\Handheld\Actions;

use App\Domain\Handheld\Data\IssuedHandheldToken;
use App\Domain\Handheld\Models\HandheldToken;
use App\Domain\Handheld\Models\HandheldUser;
use App\Domain\Handheld\Support\HandheldAccess;
use App\Domain\Handheld\Support\HandheldTokenSecret;
use App\Domain\Handheld\Support\PdaErrors;
use App\Domain\Shared\Actions\RecordAudit;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Tenancy\CurrentCompany;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use SensitiveParameter;

/**
 * `POST /api/pda/login`: email + password → a new bearer token for that handheld user (one per device).
 *
 * Emails are unique per business, not across businesses: the password picks the account. When it opens accounts in
 * more than one business (switched-on accounts only) the app must send `tenant_id` (409 TENANT_REQUIRED, with `tenants`
 * [{id, name}] of those businesses only). Wrong email or password → 401
 * INVALID_CREDENTIALS (the same answer and roughly the same time either way); the right password on a switched-off
 * account, a closed shop or a business on hold → 403 with its code (HandheldAccess). Audited as `handheld.login`;
 * the token and password are never logged.
 */
final class LogInHandheldUser
{
    /** Bcrypt checks per login at most (one email in many businesses). */
    private const MAX_CANDIDATES = 10;

    private static ?string $dummyHash = null;

    public function __construct(private readonly CurrentCompany $tenancy, private readonly RecordAudit $audit) {}

    /**
     * @throws ApiException
     */
    public function handle(string $email, #[SensitiveParameter] string $password, ?string $tenantId = null, ?string $deviceName = null): IssuedHandheldToken
    {
        $candidates = HandheldUser::withoutCompanyScope()->where('email', mb_strtolower(trim($email)))
            ->when($tenantId !== null, fn ($query) => $query->where('company_id', $tenantId))
            ->orderBy('created_at')->limit(self::MAX_CANDIDATES)->get();

        if ($candidates->isEmpty()) {
            Hash::check($password, self::$dummyHash ??= Hash::make(bin2hex(random_bytes(8)))); // Same work as a real check.

            throw PdaErrors::invalidCredentials();
        }

        $matched = $candidates->filter(fn (HandheldUser $user) => Hash::check($password, $user->password))->values();

        if ($matched->isEmpty()) {
            throw PdaErrors::invalidCredentials();
        }

        /** @var HandheldUser $user */
        $user = $matched->count() > 1 ? self::pick($matched) : $matched->first();
        [$company, $shop] = HandheldAccess::assertAllowed($user);
        $plain = HandheldTokenSecret::generate();

        $token = $this->tenancy->runAs($company, fn () => DB::transaction(function () use ($user, $plain, $deviceName) {
            $token = HandheldToken::query()->create([
                'handheld_user_id' => $user->id,
                'token_hash' => HandheldTokenSecret::hash($plain),
                'token_last4' => HandheldTokenSecret::last4($plain),
                'device_name' => $deviceName === null || trim($deviceName) === '' ? null : mb_substr(trim($deviceName), 0, 120),
            ]);

            $user->forceFill(['last_login_at' => CarbonImmutable::now()])->save();
            $this->audit->handle('handheld.login', $user, null, null, ['device' => $token->device_name, 'shop' => $user->branch_id], actor: $user);

            return $token;
        }));

        return new IssuedHandheldToken($user, $company, $shop, $token, $plain);
    }

    /**
     * The email and password open accounts in more than one business. Only the switched-on ones count: exactly one →
     * that account; none → 403 ACCOUNT_INACTIVE; several → 409 TENANT_REQUIRED listing just those businesses (the
     * password was right for each, so nothing is revealed to someone without it).
     *
     * @param  Collection<int, HandheldUser>  $matched
     *
     * @throws ApiException
     */
    private static function pick(Collection $matched): HandheldUser
    {
        $active = $matched->filter(fn (HandheldUser $user) => $user->is_active)->values();

        if ($active->isEmpty()) {
            throw PdaErrors::inactive();
        }

        if ($active->count() === 1) {
            return $active->first();
        }

        $names = Company::query()->withTrashed()->whereKey($active->pluck('company_id')->all())->pluck('name', 'id');

        throw PdaErrors::tenantRequired($active
            ->map(fn (HandheldUser $user) => ['id' => $user->company_id, 'name' => (string) ($names[$user->company_id] ?? '')])
            ->sortBy('name', SORT_NATURAL | SORT_FLAG_CASE)->values()->all());
    }
}
