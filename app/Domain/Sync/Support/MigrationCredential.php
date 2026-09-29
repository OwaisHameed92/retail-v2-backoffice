<?php

namespace App\Domain\Sync\Support;

use App\Domain\Licensing\Api\Support\LicenceApiErrors;
use App\Domain\Licensing\Api\Support\LicenceLookup;
use App\Domain\Licensing\Api\Support\WrongKeyLimiter;
use App\Domain\Licensing\Api\TillRequest;
use App\Domain\Licensing\LicenceKey;
use App\Domain\Licensing\Models\Licence;
use App\Domain\Plans\Enums\Feature;
use App\Domain\Shared\Exceptions\ApiException;
use App\Domain\Sync\Models\SyncKey;
use App\Domain\Tenancy\Enums\CompanyStatus;
use App\Domain\Tenancy\Models\Branch;
use App\Domain\Tenancy\Models\Company;
use Carbon\CarbonImmutable;
use SensitiveParameter;

/**
 * The code of a `cloud/migrate` (contract v1.4.1 §17.8, SIMPLE-SETUP.md §3): the branch's **sync key** (route A of
 * Connect; it is also the `apiKey` we answer with), or a **portal licence key** already activated on this PC (a
 * local shop that entered a portal key; it must include the dashboard, `cloud_sync`).
 *
 * Unknown → 404 activation.code_not_found; a revoked or long-replaced sync key, or a withdrawn licence key → 410
 * activation.code_expired (both count as wrong codes: 5 per install per 15 minutes, then 429
 * activation.too_many_attempts); a licence key on another PC → 409 activation.code_used; a closed shop, a suspended or
 * cancelled business, a licence key not yet activated here or without the dashboard → 403 licence.not_active.
 */
final class MigrationCredential
{
    public function __construct(
        private readonly WrongKeyLimiter $wrongCodes,
        private readonly LicenceLookup $lookup,
    ) {}

    /**
     * @return array{branch: Branch, syncKey: SyncKey|null, licence: Licence|null}
     *
     * @throws ApiException
     */
    public function resolve(#[SensitiveParameter] string $code, TillRequest $till): array
    {
        $this->wrongCodes->ensureAllowed($till->installId);

        try {
            $found = SyncKeySecret::looksValid($code) ? $this->syncKey($code) : $this->licenceKey($code, $till);
        } catch (ApiException $e) {
            if (in_array($e->errorCode, ['activation.code_not_found', 'activation.code_expired'], true)) {
                $this->wrongCodes->hit($till->installId);
            }

            throw $e;
        }

        $branch = $found['branch'];
        $company = Company::query()->find($branch->company_id);

        if (! $branch->is_active || $company === null || in_array($company->status, [CompanyStatus::Suspended, CompanyStatus::Cancelled], true)) {
            throw LicenceApiErrors::notActive('suspended', 'This shop is not active on the portal.');
        }

        return $found;
    }

    /**
     * @return array{branch: Branch, syncKey: SyncKey, licence: null}
     */
    private function syncKey(#[SensitiveParameter] string $code): array
    {
        $key = SyncKey::withoutCompanyScope()->where('key_hash', SyncKeySecret::hash($code))->first() ?? throw MigrationErrors::codeNotFound();

        if (! $key->isUsable(CarbonImmutable::now())) {
            throw MigrationErrors::codeExpired();
        }

        $branch = Branch::withoutCompanyScope()->find($key->branch_id) ?? throw MigrationErrors::codeNotFound();

        return ['branch' => $branch, 'syncKey' => $key, 'licence' => null];
    }

    /**
     * @return array{branch: Branch, syncKey: null, licence: Licence}
     */
    private function licenceKey(#[SensitiveParameter] string $code, TillRequest $till): array
    {
        $key = LicenceKey::tryParse($code) ?? throw MigrationErrors::codeNotFound();

        try {
            $licence = $this->lookup->byKey($key, $till);
        } catch (ApiException $e) {
            throw $e->errorCode === 'key.expired' ? MigrationErrors::codeExpired() : MigrationErrors::codeNotFound();
        }

        if ($licence->isRevoked()) {
            throw MigrationErrors::codeExpired();
        }

        if ($licence->isBound() && $licence->device_id !== $till->installId) {
            throw MigrationErrors::codeUsed($licence->device_name, $licence->bound_at);
        }

        if (! $licence->isBound()) {
            throw LicenceApiErrors::notActive($licence->status->value, 'Enter this licence key under Settings > Licence first, then connect.');
        }

        if (! $licence->features->contains(Feature::CloudSync)) {
            throw LicenceApiErrors::notActive($licence->status->value, 'This licence does not include the online dashboard.');
        }

        $branch = Branch::withoutCompanyScope()->find($licence->branch_id) ?? throw MigrationErrors::codeNotFound();

        return ['branch' => $branch, 'syncKey' => null, 'licence' => $licence];
    }
}
