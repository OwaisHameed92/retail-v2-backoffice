<?php

namespace App\Domain\Sync\Actions;

use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Data\SyncCaller;
use App\Domain\Sync\Enums\IdKind;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Sync\Support\IdTranslator;
use App\Domain\Sync\Support\SyncApiErrors;
use App\Domain\Sync\Support\SyncKeySecret;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use App\Domain\Tenancy\Models\Register;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * Who is calling `sync/*` (module 2.1, contract §2.3, §3, §4.1): the Bearer sync key → its branch (HMAC lookup),
 * and `X-SSPOS-Company-Id` / `X-SSPOS-Branch-Id` — the till's own ids, translated through id_map — must name that
 * key's company and branch.
 *
 * - no, malformed or unknown key → 401 auth.invalid_key;
 * - revoked, replaced more than 7 days ago, branch deactivated or business cancelled → 401 auth.key_revoked;
 * - branch header missing or another branch, company header another company → 403 auth.wrong_branch.
 *
 * Suspension and licence checks belong to the endpoints (2.2). The key's last use is written at most once per
 * config('sync.touch_every_seconds').
 */
class AuthenticateSyncRequest
{
    /**
     * @throws ApiException
     */
    public function handle(#[SensitiveParameter] ?string $bearer, ?string $companyHeader, ?string $branchHeader, ?string $registerHeader): SyncCaller
    {
        $bearer = trim((string) $bearer);

        if ($bearer === '' || ! SyncKeySecret::looksValid($bearer)) {
            throw SyncApiErrors::invalidKey();
        }

        $now = CarbonImmutable::now();
        $key = SyncKey::withoutCompanyScope()->whereIn('key_hash', SyncKeySecret::hashCandidates($bearer))->first() ?? throw SyncApiErrors::invalidKey();
        $branch = Branch::withoutCompanyScope()->find($key->branch_id);
        $company = Company::query()->find($key->company_id);

        if (! $key->isUsable($now) || $branch === null || ! $branch->is_active || $company === null || $company->status === CompanyStatus::Cancelled) {
            throw SyncApiErrors::keyRevoked();
        }

        $ids = IdTranslator::forCompany($company->id);
        $tillBranch = trim((string) $branchHeader);
        $tillCompany = trim((string) $companyHeader);

        if ($tillBranch === '' || $ids->toPortal(IdKind::Branch, $tillBranch) !== $branch->id) {
            throw SyncApiErrors::wrongBranch();
        }

        if ($tillCompany !== '' && $ids->toPortal(IdKind::Company, $tillCompany) !== $company->id) {
            throw SyncApiErrors::wrongBranch();
        }

        $this->touch($key, $now);
        self::rehash($key, $bearer);

        return new SyncCaller($company, $branch, $key->id, $this->register($ids, $branch, trim((string) $registerHeader)), $tillCompany, $tillBranch, $ids);
    }

    private function register(IdTranslator $ids, Branch $branch, string $header): ?string
    {
        if ($header === '') {
            return null;
        }

        $id = $ids->toPortal(IdKind::Register, $header);

        return Register::withoutCompanyScope()->withTrashed()->where('branch_id', $branch->id)->whereKey($id)->exists() ? $id : null;
    }

    /** A key found under an APP_PREVIOUS_KEYS entry is stored again under the current APP_KEY (L10). */
    private static function rehash(SyncKey $key, #[SensitiveParameter] string $bearer): void
    {
        $current = SyncKeySecret::hash($bearer);

        if (! hash_equals($key->key_hash, $current)) {
            SyncKey::withoutCompanyScope()->whereKey($key->id)->update(['key_hash' => $current]);
        }
    }

    private function touch(SyncKey $key, CarbonImmutable $now): void
    {
        $every = max(1, (int) config('sync.touch_every_seconds', 60));

        if ($key->last_used_at === null || $key->last_used_at->addSeconds($every)->lessThanOrEqualTo($now)) {
            SyncKey::withoutCompanyScope()->whereKey($key->id)->update(['last_used_at' => $now]);
        }
    }
}
